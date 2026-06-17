<?php

namespace Broxy\Server;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Channel\Client as ChannelClient;

/**
 * HTTP API Server - Accepts URL render requests and routes them through browser bots.
 *
 * Usage:
 *   curl "http://localhost:8080/api?url=https://example.com"
 *   curl -X POST http://localhost:8080/api \
 *     -H "Content-Type: application/json" \
 *     -d '{"url":"https://example.com"}'
 */
class ApiServer
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

        $address = "http://{$config['api']['host']}:{$config['api']['port']}";
        $this->worker = new Worker($address);
        $this->worker->name = 'BroxyApiServer';
        $this->worker->count = $config['api']['workers'];

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
        echo "API server worker {$worker->id} started\n";

        // Connect to Channel server for IPC
        ChannelClient::connect(
            $this->config['channel']['host'],
            $this->config['channel']['port']
        );

        // Subscribe to browser render responses from control server.
        ChannelClient::on('api_response', function ($data) {
            $this->handleApiResponse($data);
        });
    }

    private function onMessage(TcpConnection $connection, Request $request): void
    {
        if ($request->path() === '/api') {
            $this->handleApiRequest($connection, $request);
            return;
        }

        $this->sendJson($connection, 404, [
            'error' => 'Not Found',
            'message' => 'Use GET /api?url=https://example.com or POST /api with {"url":"https://example.com"}.',
        ]);
    }

    private function handleApiRequest(TcpConnection $connection, Request $request): void
    {
        $method = strtoupper($request->method());

        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->sendJson($connection, 405, [
                'error' => 'Method Not Allowed',
                'message' => 'Use GET or POST.',
            ], ['Allow' => 'GET, POST']);
            return;
        }

        $url = $method === 'GET'
            ? $this->extractUrlFromQuery($request)
            : $this->extractUrlFromBody($request);

        if (!$url) {
            $this->sendJson($connection, 400, [
                'error' => 'Bad Request',
                'message' => 'Missing url. Use GET /api?url=https://example.com or POST /api with {"url":"https://example.com"}.',
            ]);
            return;
        }

        if (!$this->isSupportedUrl($url)) {
            $this->sendJson($connection, 422, [
                'error' => 'Unprocessable Entity',
                'message' => 'url must start with http:// or https://.',
            ]);
            return;
        }

        $this->forwardRequest($connection, $url);
    }

    private function forwardRequest(
        TcpConnection $connection,
        string $targetUrl
    ): void {
        // Generate unique request ID
        $requestId = uniqid('req_', true);

        // Store connection for response routing
        $this->pendingConnections[$requestId] = $connection;
        $this->requestTimestamps[$requestId] = microtime(true);

        // Forward request to control server via Channel
        ChannelClient::publish('api_request', [
            'request_id' => $requestId,
            'method' => 'GET',
            'url' => $targetUrl,
            'headers' => [],
            'body' => null,
        ]);

        echo "Forwarded API request {$requestId}: {$targetUrl}\n";
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

    private function handleApiResponse(array $data): void
    {
        $requestId = $data['request_id'] ?? null;

        if (!$requestId || !isset($this->pendingConnections[$requestId])) {
            return;
        }

        $connection = $this->pendingConnections[$requestId];
        unset($this->pendingConnections[$requestId]);
        unset($this->requestTimestamps[$requestId]);

        $this->sendJson($connection, isset($data['error']) ? ($data['status'] ?? 502) : 200, [
            'request_id' => $requestId,
            'ok' => !isset($data['error']),
            'status' => $data['status'] ?? null,
            'headers' => $data['headers'] ?? [],
            'body' => $data['body'] ?? '',
            'error' => $data['error'] ?? null,
        ]);
    }

    private function extractUrlFromQuery(Request $request): ?string
    {
        $url = $request->get('url');
        return is_string($url) && $url !== '' ? $url : null;
    }

    private function extractUrlFromBody(Request $request): ?string
    {
        $rawBody = trim($request->rawBody());

        if ($rawBody === '') {
            return null;
        }

        $contentType = strtolower((string) $request->header('content-type', ''));

        if (str_contains($contentType, 'application/json')) {
            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                return null;
            }

            $url = $payload['url'] ?? null;
            return is_string($url) && $url !== '' ? $url : null;
        }

        parse_str($rawBody, $payload);
        $url = $payload['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function isSupportedUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function sendJson(
        TcpConnection $connection,
        int $status,
        array $payload,
        array $headers = []
    ): void {
        $connection->send(new Response(
            $status,
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        ));
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }
}
