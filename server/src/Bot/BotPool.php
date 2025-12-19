<?php

namespace Broxy\Bot;

/**
 * Manages the pool of connected browser bots
 */
class BotPool
{
    /** @var Bot[] */
    private array $bots = [];

    /** @var array<int, string> Maps connection ID to bot ID */
    private array $connectionMap = [];

    private int $lastSelectedIndex = -1;

    public function add(Bot $bot): void
    {
        $this->bots[$bot->getId()] = $bot;
        $this->connectionMap[$bot->getConnectionId()] = $bot->getId();
    }

    public function remove(string $botId): void
    {
        if (isset($this->bots[$botId])) {
            $connectionId = $this->bots[$botId]->getConnectionId();
            unset($this->connectionMap[$connectionId]);
            unset($this->bots[$botId]);
        }
    }

    public function removeByConnectionId(int $connectionId): ?Bot
    {
        if (isset($this->connectionMap[$connectionId])) {
            $botId = $this->connectionMap[$connectionId];
            $bot = $this->bots[$botId] ?? null;
            $this->remove($botId);
            return $bot;
        }
        return null;
    }

    public function get(string $botId): ?Bot
    {
        return $this->bots[$botId] ?? null;
    }

    public function getByConnectionId(int $connectionId): ?Bot
    {
        $botId = $this->connectionMap[$connectionId] ?? null;
        return $botId ? ($this->bots[$botId] ?? null) : null;
    }

    /**
     * Select an available bot using round-robin selection
     */
    public function selectAvailable(): ?Bot
    {
        $availableBots = $this->getAvailable();
        
        if (empty($availableBots)) {
            return null;
        }

        $availableBots = array_values($availableBots);
        $count = count($availableBots);
        
        // Round-robin selection
        $this->lastSelectedIndex = ($this->lastSelectedIndex + 1) % $count;
        
        return $availableBots[$this->lastSelectedIndex];
    }

    /**
     * Select a random available bot
     */
    public function selectRandomAvailable(): ?Bot
    {
        $availableBots = $this->getAvailable();
        
        if (empty($availableBots)) {
            return null;
        }

        return $availableBots[array_rand($availableBots)];
    }

    /**
     * Get all available bots
     * @return Bot[]
     */
    public function getAvailable(): array
    {
        return array_filter($this->bots, fn(Bot $bot) => $bot->isAvailable());
    }

    /**
     * Get all bots
     * @return Bot[]
     */
    public function getAll(): array
    {
        return $this->bots;
    }

    public function count(): int
    {
        return count($this->bots);
    }

    public function countAvailable(): int
    {
        return count($this->getAvailable());
    }

    /**
     * Remove bots that haven't sent heartbeat within timeout
     * @return Bot[] Removed bots
     */
    public function cleanupStale(float $timeoutSeconds): array
    {
        $now = microtime(true);
        $staleBots = [];

        foreach ($this->bots as $bot) {
            if (($now - $bot->getLastHeartbeat()) > $timeoutSeconds) {
                $staleBots[] = $bot;
                $this->remove($bot->getId());
            }
        }

        return $staleBots;
    }

    public function getStats(): array
    {
        $stats = [
            'total' => $this->count(),
            'available' => 0,
            'busy' => 0,
        ];

        foreach ($this->bots as $bot) {
            if ($bot->isAvailable()) {
                $stats['available']++;
            } elseif ($bot->isBusy()) {
                $stats['busy']++;
            }
        }

        return $stats;
    }
}

