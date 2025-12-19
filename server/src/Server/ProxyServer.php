<?php

namespace Broxy\Server;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Channel\Client as ChannelClient;

/**
 * HTTP Proxy Server - Accepts incoming HTTP requests and routes them through browser bots
 *
 * Usage:
 *   curl http://localhost:8080/https://example.com/path
 *   curl http://localhost:8080/http://example.com/path
 */
class ProxyServer
{
    private Worker $worker;
    private array $config;

    /** @var array<string, TcpConnection> Maps request_id to client connection */
    private array $pendingConnections = [];

    /** @var array<string, float> Maps request_id to creation timestamp */
    private array $requestTimestamps = [];

    public function __construct(array $config)
    {
        $this->config = $config;

        $address = "http://{$config['proxy']['host']}:{$config['proxy']['port']}";
        $this->worker = new Worker($address);
        $this->worker->name = 'BroxyProxyServer';
        $this->worker->count = $config['proxy']['workers'];

        $this->setupCallbacks();
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = function (Worker $worker) {
            $this->onWorkerStart($worker);
        };

        $this->worker->onMessage = function (TcpConnection $connection, Request $request) {
            $this->onMessage($connection, $request);
        };

        $this->worker->onClose = function (TcpConnection $connection) {
            $this->onClose($connection);
        };
    }

    private function onWorkerStart(Worker $worker): void
    {
        echo "Proxy server worker {$worker->id} started\n";

        // Connect to Channel server for IPC
        ChannelClient::connect(
            $this->config['channel']['host'],
            $this->config['channel']['port']
        );

        // Subscribe to responses from control server
        ChannelClient::on('proxy_response', function ($data) {
            $this->handleProxyResponse($data);
        });
    }

    private function onMessage(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        $headers = $request->header();
        $body = $request->rawBody();

        // Handle CONNECT method (HTTPS proxy requests)
        if ($method === 'CONNECT') {
            $this->handleConnect($connection, $request);
            return;
        }

        // Parse the target URL from the request
        $targetUrl = $this->parseTargetUrl($request);

        if (!$targetUrl) {
            $response = new Response(400, [], json_encode([
                'error' => 'Bad Request',
                'message' => 'Use as HTTP proxy: curl -x http://localhost:8080 http://example.com',
            ]));
            $connection->send($response);
            return;
        }

        $this->forwardRequest($connection, $method, $targetUrl, $headers, $body);
    }

    private function handleConnect(TcpConnection $connection, Request $request): void
    {
        // We only support HTTP URLs - browser will handle redirects to HTTPS
        $response = new Response(400, [], 'Use HTTP URLs only. Example: curl -x http://localhost:8080 http://example.com');
        $connection->send($response);
    }

    private function forwardRequest(
        TcpConnection $connection,
        string $method,
        string $targetUrl,
        array $headers,
        ?string $body
    ): void {
        // Generate unique request ID
        $requestId = uniqid('req_', true);

        // Store connection for response routing
        $this->pendingConnections[$requestId] = $connection;
        $this->requestTimestamps[$requestId] = microtime(true);

        // Forward request to control server via Channel
        ChannelClient::publish('proxy_request', [
            'request_id' => $requestId,
            'method' => $method,
            'url' => $targetUrl,
            'headers' => $this->filterProxyHeaders($headers),
            'body' => $body,
        ]);

        echo "Forwarded request {$requestId}: {$method} {$targetUrl}\n";
    }

    private function onClose(TcpConnection $connection): void
    {
        // Clean up any pending requests for this connection
        foreach ($this->pendingConnections as $requestId => $conn) {
            if ($conn === $connection) {
                unset($this->pendingConnections[$requestId]);
                unset($this->requestTimestamps[$requestId]);
            }
        }
    }

    private function handleProxyResponse(array $data): void
    {
        $requestId = $data['request_id'] ?? null;

        if (!$requestId || !isset($this->pendingConnections[$requestId])) {
            return;
        }

        $connection = $this->pendingConnections[$requestId];
        unset($this->pendingConnections[$requestId]);
        unset($this->requestTimestamps[$requestId]);

        if (isset($data['error'])) {
            $response = new Response($data['status'] ?? 502, [], $data['body'] ?? 'Bad Gateway');
            $connection->send($response);
            return;
        }

        // Build and send response
        $response = new Response(
            $data['status'],
            $data['headers'],
            $data['body']
        );

        $connection->send($response);
    }

    private function parseTargetUrl(Request $request): ?string
    {
        $uri = $request->uri();

        // Standard HTTP proxy: full URL in request line (e.g., GET http://example.com/path)
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return null;
    }

    private function filterProxyHeaders(array $headers): array
    {
        // Remove hop-by-hop headers that shouldn't be forwarded
        $hopByHop = [
            'proxy-authorization',
            'proxy-authenticate',
            'proxy-connection',
            'connection',
            'keep-alive',
            'te',
            'trailers',
            'transfer-encoding',
            'upgrade',
        ];

        return array_filter(
            $headers,
            fn($key) => !in_array(strtolower($key), $hopByHop),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }
}

