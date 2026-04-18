<?php

declare(strict_types=1);

namespace Strawberry\Internal;

final class Diagnostics
{
    private int $sent = 0;
    private int $failed = 0;
    private int $retried = 0;
    private float $startedAt;

    /** @var array<string,int> */
    private array $byEndpointSent = [];

    /** @var array<string,int> */
    private array $byEndpointFailed = [];

    public function __construct(private readonly ClockInterface $clock)
    {
        $this->startedAt = $this->clock->monotonic();
    }

    public function recordSent(string $endpoint, int $count): void
    {
        $this->sent += $count;
        $this->byEndpointSent[$endpoint] = ($this->byEndpointSent[$endpoint] ?? 0) + $count;
    }

    public function recordFailed(string $endpoint, int $count): void
    {
        $this->failed += $count;
        $this->byEndpointFailed[$endpoint] = ($this->byEndpointFailed[$endpoint] ?? 0) + $count;
    }

    public function recordRetry(): void
    {
        $this->retried++;
    }

    /** @return array<string,mixed> */
    public function snapshot(BufferSet $buffers, array $breakerStates): array
    {
        return [
            'uptime_seconds' => $this->clock->monotonic() - $this->startedAt,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'retried' => $this->retried,
            'by_endpoint' => [
                'sent' => $this->byEndpointSent,
                'failed' => $this->byEndpointFailed,
            ],
            'buffers' => [
                'sizes' => $buffers->sizes(),
                'enqueued' => $buffers->enqueuedCounts(),
                'dropped' => $buffers->droppedCounts(),
            ],
            'circuit_breakers' => $breakerStates,
        ];
    }
}
