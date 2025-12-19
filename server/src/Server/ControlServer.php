<?php

namespace Broxy\Server;

use Broxy\Bot\Bot;
use Broxy\Bot\BotPool;
use Broxy\Request\PendingRequest;
use Broxy\Request\RequestQueue;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Channel\Client as ChannelClient;

/**
 * WebSocket Control Server - Manages bot pool and request routing
 */
class ControlServer
{
    private Worker $worker;
    private BotPool $botPool;
    private RequestQueue $requestQueue;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->botPool = new BotPool();
        $this->requestQueue = new RequestQueue(
            $config['queue']['max_pending'],
            $config['queue']['request_ttl']
        );

        $address = "websocket://{$config['control']['host']}:{$config['control']['port']}";
        $this->worker = new Worker($address);
        $this->worker->name = 'BroxyControlServer';
        $this->worker->count = $config['control']['workers'];

        $this->setupCallbacks();
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = function (Worker $worker) {
            $this->onWorkerStart($worker);
        };

        $this->worker->onConnect = function (TcpConnection $connection) {
            $this->onConnect($connection);
        };

        $this->worker->onMessage = function (TcpConnection $connection, $data) {
            $this->onMessage($connection, $data);
        };

        $this->worker->onClose = function (TcpConnection $connection) {
            $this->onClose($connection);
        };
    }

    private function onWorkerStart(Worker $worker): void
    {
        echo "Control server worker {$worker->id} started\n";

        // Connect to Channel server for IPC
        ChannelClient::connect(
            $this->config['channel']['host'],
            $this->config['channel']['port']
        );

        // Subscribe to proxy requests
        ChannelClient::on('proxy_request', function ($data) {
            $this->handleProxyRequest($data);
        });

        // Setup heartbeat checker timer
        Timer::add($this->config['bot']['heartbeat_interval'], function () {
            $this->checkHeartbeats();
            $this->cleanupExpiredRequests();
        });
    }

    private function onConnect(TcpConnection $connection): void
    {
        echo "New connection from {$connection->getRemoteIp()}\n";
        // Bot will authenticate via message
    }

    private function onMessage(TcpConnection $connection, $data): void
    {
        $message = json_decode($data, true);

        if (!$message || !isset($message['type'])) {
            $connection->send(json_encode(['error' => 'Invalid message format']));
            return;
        }

        switch ($message['type']) {
            case 'auth':
                $this->handleAuth($connection, $message);
                break;
            case 'pong':
                $this->handlePong($connection);
                break;
            case 'response':
                $this->handleResponse($connection, $message);
                break;
            default:
                $connection->send(json_encode(['error' => 'Unknown message type']));
        }
    }

    private function onClose(TcpConnection $connection): void
    {
        $bot = $this->botPool->removeByConnectionId($connection->id);
        if ($bot) {
            echo "Bot {$bot->getId()} disconnected\n";
            // Handle any pending request assigned to this bot
            $pendingRequest = $this->requestQueue->findByBotId($bot->getId());
            if ($pendingRequest) {
                $this->reassignRequest($pendingRequest);
            }
        }
    }

    private function handleAuth(TcpConnection $connection, array $message): void
    {
        $metadata = [
            'ip' => $connection->getRemoteIp(),
            'userAgent' => $message['user_agent'] ?? 'Unknown',
            'browser' => $message['browser'] ?? 'Unknown',
            'platform' => $message['platform'] ?? 'Unknown',
        ];

        $bot = new Bot($connection->id, $metadata);
        $this->botPool->add($bot);

        $connection->send(json_encode([
            'type' => 'auth_success',
            'bot_id' => $bot->getId(),
            'heartbeat_interval' => $this->config['bot']['heartbeat_interval'] * 1000,
        ]));

        echo "Bot {$bot->getId()} authenticated from {$metadata['ip']}\n";
        $this->processQueue();
    }

    private function handlePong(TcpConnection $connection): void
    {
        $bot = $this->botPool->getByConnectionId($connection->id);
        if ($bot) {
            $bot->updateHeartbeat();
        }
    }

    private function handleResponse(TcpConnection $connection, array $message): void
    {
        $requestId = $message['request_id'] ?? null;

        if (!$requestId) {
            return;
        }

        $bot = $this->botPool->getByConnectionId($connection->id);
        if ($bot) {
            $bot->markAvailable();
        }

        // Publish response back to proxy server via Channel
        ChannelClient::publish('proxy_response', [
            'request_id' => $requestId,
            'status' => $message['status'] ?? 500,
            'headers' => $message['headers'] ?? [],
            'body' => $message['body'] ?? '',
            'error' => $message['error'] ?? null,
        ]);

        $this->requestQueue->remove($requestId);
        $this->processQueue();
    }

    private function handleProxyRequest(array $data): void
    {
        // This is called when proxy server sends a request via Channel
        $request = new PendingRequest(
            $data['method'],
            $data['url'],
            $data['headers'],
            $data['body'] ?? null,
            null, // No direct connection in this context
            $data['request_id'] // Use the request ID from proxy server
        );

        // Store mapping for response routing
        $this->requestQueue->add($request);
        $this->processQueue();
    }

    private function processQueue(): void
    {
        while (true) {
            $request = $this->requestQueue->getNextUnassigned();
            if (!$request) {
                break;
            }

            $bot = $this->botPool->selectAvailable();
            if (!$bot) {
                break;
            }

            $this->assignRequestToBot($request, $bot);
        }
    }

    private function assignRequestToBot(PendingRequest $request, Bot $bot): void
    {
        $request->assignToBot($bot->getId());
        $bot->markBusy($request->getId());

        $connection = $this->getConnectionById($bot->getConnectionId());
        if ($connection) {
            $connection->send(json_encode($request->toTaskPayload()));
            echo "Assigned request {$request->getId()} to bot {$bot->getId()}\n";
        }
    }

    private function reassignRequest(PendingRequest $request): void
    {
        // Reset assignment and try to reassign
        $request->assignToBot(''); // Clear assignment
        $this->processQueue();
    }

    private function getConnectionById(int $connectionId): ?TcpConnection
    {
        return $this->worker->connections[$connectionId] ?? null;
    }

    private function checkHeartbeats(): void
    {
        $staleBots = $this->botPool->cleanupStale($this->config['bot']['heartbeat_timeout']);

        foreach ($staleBots as $bot) {
            echo "Bot {$bot->getId()} timed out (no heartbeat)\n";
            $connection = $this->getConnectionById($bot->getConnectionId());
            if ($connection) {
                $connection->close();
            }
        }

        // Send ping to all connected bots
        foreach ($this->botPool->getAll() as $bot) {
            $connection = $this->getConnectionById($bot->getConnectionId());
            if ($connection) {
                $connection->send(json_encode(['type' => 'ping']));
            }
        }
    }

    private function cleanupExpiredRequests(): void
    {
        $expired = $this->requestQueue->cleanupExpired();

        foreach ($expired as $request) {
            ChannelClient::publish('proxy_response', [
                'request_id' => $request->getId(),
                'status' => 504,
                'headers' => [],
                'body' => 'Gateway Timeout - Request expired in queue',
                'error' => 'timeout',
            ]);
        }
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }

    public function getStats(): array
    {
        return [
            'bots' => $this->botPool->getStats(),
            'requests' => $this->requestQueue->getStats(),
        ];
    }
}

