<?php

namespace Broxy\Bot;

/**
 * Represents a connected browser bot
 */
class Bot
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BUSY = 'busy';
    public const STATUS_DISCONNECTED = 'disconnected';

    private string $id;
    private string $status;
    private ?string $currentRequestId = null;
    private int $connectionId;
    private array $metadata;
    private float $lastHeartbeat;
    private float $connectedAt;

    public function __construct(int $connectionId, array $metadata = [])
    {
        $this->id = uniqid('bot_', true);
        $this->connectionId = $connectionId;
        $this->status = self::STATUS_AVAILABLE;
        $this->metadata = $metadata;
        $this->lastHeartbeat = microtime(true);
        $this->connectedAt = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getConnectionId(): int
    {
        return $this->connectionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isBusy(): bool
    {
        return $this->status === self::STATUS_BUSY;
    }

    public function markBusy(string $requestId): void
    {
        $this->status = self::STATUS_BUSY;
        $this->currentRequestId = $requestId;
    }

    public function markAvailable(): void
    {
        $this->status = self::STATUS_AVAILABLE;
        $this->currentRequestId = null;
    }

    public function markDisconnected(): void
    {
        $this->status = self::STATUS_DISCONNECTED;
    }

    public function getCurrentRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    public function updateHeartbeat(): void
    {
        $this->lastHeartbeat = microtime(true);
    }

    public function getLastHeartbeat(): float
    {
        return $this->lastHeartbeat;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    public function getIp(): ?string
    {
        return $this->metadata['ip'] ?? null;
    }

    public function getUserAgent(): ?string
    {
        return $this->metadata['userAgent'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'currentRequestId' => $this->currentRequestId,
            'metadata' => $this->metadata,
            'lastHeartbeat' => $this->lastHeartbeat,
            'connectedAt' => $this->connectedAt,
        ];
    }
}

