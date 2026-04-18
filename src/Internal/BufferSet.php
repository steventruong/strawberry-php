<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Per-channel bounded buffers with drop-oldest semantics.
 *
 * Channels: events, errors, logs, identify, llm.
 * Each channel has its own quota; when full, the oldest item is dropped and
 * a counter is incremented so diagnostics can expose backpressure.
 */
final class BufferSet
{
    public const CH_EVENTS = 'events';
    public const CH_ERRORS = 'errors';
    public const CH_LOGS = 'logs';
    public const CH_IDENTIFY = 'identify';
    public const CH_LLM = 'llm';

    /** @var array<string,list<array<string,mixed>>> */
    private array $buffers = [
        self::CH_EVENTS => [],
        self::CH_ERRORS => [],
        self::CH_LOGS => [],
        self::CH_IDENTIFY => [],
        self::CH_LLM => [],
    ];

    /** @var array<string,int> */
    private array $quotas;

    /** @var array<string,int> */
    private array $dropped = [
        self::CH_EVENTS => 0,
        self::CH_ERRORS => 0,
        self::CH_LOGS => 0,
        self::CH_IDENTIFY => 0,
        self::CH_LLM => 0,
    ];

    /** @var array<string,int> */
    private array $enqueued = [
        self::CH_EVENTS => 0,
        self::CH_ERRORS => 0,
        self::CH_LOGS => 0,
        self::CH_IDENTIFY => 0,
        self::CH_LLM => 0,
    ];

    /**
     * @param array<string,int> $quotas Optional per-channel overrides.
     */
    public function __construct(array $quotas = [])
    {
        $this->quotas = array_merge([
            self::CH_EVENTS => 10_000,
            self::CH_ERRORS => 1_000,
            self::CH_LOGS => 5_000,
            self::CH_IDENTIFY => 1_000,
            self::CH_LLM => 2_000,
        ], $quotas);
    }

    /**
     * @param array<string,mixed> $item
     */
    public function push(string $channel, array $item): void
    {
        if (!isset($this->buffers[$channel])) {
            return;
        }
        $this->enqueued[$channel]++;
        $this->buffers[$channel][] = $item;

        $quota = $this->quotas[$channel];
        $overflow = count($this->buffers[$channel]) - $quota;
        if ($overflow > 0) {
            array_splice($this->buffers[$channel], 0, $overflow);
            $this->dropped[$channel] += $overflow;
        }
    }

    /**
     * Drain up to $max items from a channel. Returns the drained batch and
     * leaves the rest in the buffer.
     *
     * @return list<array<string,mixed>>
     */
    public function drain(string $channel, int $max = 100): array
    {
        if (!isset($this->buffers[$channel])) {
            return [];
        }
        if ($this->buffers[$channel] === []) {
            return [];
        }
        if ($max <= 0 || $max >= count($this->buffers[$channel])) {
            $batch = $this->buffers[$channel];
            $this->buffers[$channel] = [];
            return $batch;
        }
        $batch = array_splice($this->buffers[$channel], 0, $max);
        return $batch;
    }

    /** Put items back at the head of a channel (retry after failed send). */
    public function requeueFront(string $channel, array $items): void
    {
        if (!isset($this->buffers[$channel]) || $items === []) {
            return;
        }
        $merged = array_merge($items, $this->buffers[$channel]);
        $this->buffers[$channel] = $merged;

        $quota = $this->quotas[$channel];
        $overflow = count($this->buffers[$channel]) - $quota;
        if ($overflow > 0) {
            // Drop from tail to preserve the reclaimed items.
            array_splice($this->buffers[$channel], -$overflow);
            $this->dropped[$channel] += $overflow;
        }
    }

    public function size(string $channel): int
    {
        return isset($this->buffers[$channel]) ? count($this->buffers[$channel]) : 0;
    }

    public function totalSize(): int
    {
        $t = 0;
        foreach ($this->buffers as $b) {
            $t += count($b);
        }
        return $t;
    }

    /** @return array<string,int> */
    public function droppedCounts(): array
    {
        return $this->dropped;
    }

    /** @return array<string,int> */
    public function enqueuedCounts(): array
    {
        return $this->enqueued;
    }

    /** @return array<string,int> */
    public function sizes(): array
    {
        return [
            self::CH_EVENTS => count($this->buffers[self::CH_EVENTS]),
            self::CH_ERRORS => count($this->buffers[self::CH_ERRORS]),
            self::CH_LOGS => count($this->buffers[self::CH_LOGS]),
            self::CH_IDENTIFY => count($this->buffers[self::CH_IDENTIFY]),
            self::CH_LLM => count($this->buffers[self::CH_LLM]),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->totalSize() === 0;
    }
}
