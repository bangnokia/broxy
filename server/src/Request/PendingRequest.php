<?php

namespace Broxy\Request;

use Workerman\Connection\TcpConnection;

/**
 * Represents a pending HTTP request waiting for bot response
 */
class PendingRequest
{
    private string $id;
    private string $method;
    private string $url;
    private array $headers;
    private ?string $body;
    private ?TcpConnection $clientConnection;
    private float $createdAt;
    private ?string $assignedBotId = null;
    private ?float $assignedAt = null;

    public function __construct(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?TcpConnection $clientConnection = null,
        ?string $requestId = null
    ) {
        $this->id = $requestId ?? uniqid('req_', true);
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->clientConnection = $clientConnection;
        $this->createdAt = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getClientConnection(): ?TcpConnection
    {
        return $this->clientConnection;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    public function assignToBot(string $botId): void
    {
        $this->assignedBotId = $botId;
        $this->assignedAt = microtime(true);
    }

    public function getAssignedBotId(): ?string
    {
        return $this->assignedBotId;
    }

    public function isAssigned(): bool
    {
        return $this->assignedBotId !== null;
    }

    public function toTaskPayload(): array
    {
        return [
            'type' => 'request',
            'request_id' => $this->id,
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }
}

