<?php

namespace Broxy\Request;

/**
 * Manages the queue of pending requests
 */
class RequestQueue
{
    /** @var PendingRequest[] */
    private array $pending = [];

    private int $maxPending;
    private float $requestTtl;

    public function __construct(int $maxPending = 1000, float $requestTtl = 60.0)
    {
        $this->maxPending = $maxPending;
        $this->requestTtl = $requestTtl;
    }

    public function add(PendingRequest $request): bool
    {
        if (count($this->pending) >= $this->maxPending) {
            return false;
        }

        $this->pending[$request->getId()] = $request;
        return true;
    }

    public function get(string $requestId): ?PendingRequest
    {
        return $this->pending[$requestId] ?? null;
    }

    public function remove(string $requestId): ?PendingRequest
    {
        $request = $this->pending[$requestId] ?? null;
        unset($this->pending[$requestId]);
        return $request;
    }

    /**
     * Get next unassigned request from queue
     */
    public function getNextUnassigned(): ?PendingRequest
    {
        foreach ($this->pending as $request) {
            if (!$request->isAssigned()) {
                return $request;
            }
        }
        return null;
    }

    /**
     * Get all unassigned requests
     * @return PendingRequest[]
     */
    public function getUnassigned(): array
    {
        return array_filter($this->pending, fn($r) => !$r->isAssigned());
    }

    public function count(): int
    {
        return count($this->pending);
    }

    public function countUnassigned(): int
    {
        return count($this->getUnassigned());
    }

    /**
     * Cleanup expired requests
     * @return PendingRequest[] Expired requests
     */
    public function cleanupExpired(): array
    {
        $expired = [];
        
        foreach ($this->pending as $id => $request) {
            if ($request->getAge() > $this->requestTtl) {
                $expired[] = $request;
                unset($this->pending[$id]);
            }
        }

        return $expired;
    }

    /**
     * Find request assigned to a specific bot
     */
    public function findByBotId(string $botId): ?PendingRequest
    {
        foreach ($this->pending as $request) {
            if ($request->getAssignedBotId() === $botId) {
                return $request;
            }
        }
        return null;
    }

    public function getStats(): array
    {
        return [
            'total' => $this->count(),
            'unassigned' => $this->countUnassigned(),
            'assigned' => $this->count() - $this->countUnassigned(),
        ];
    }
}

