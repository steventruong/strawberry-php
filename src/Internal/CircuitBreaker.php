<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Per-endpoint circuit breaker.
 *
 * States:
 *   CLOSED      - normal, all requests allowed
 *   OPEN        - short-circuit, allow() returns false until cooldown expires
 *   HALF_OPEN   - allow a single probe; success -> CLOSED, failure -> OPEN
 */
final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $consecutiveFailures = 0;
    private float $openedAt = 0.0;
    private bool $probeInFlight = false;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 30.0,
    ) {
    }

    public function allow(): bool
    {
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state === self::STATE_OPEN) {
            if ($this->clock->monotonic() - $this->openedAt >= $this->cooldownSeconds) {
                $this->state = self::STATE_HALF_OPEN;
                $this->probeInFlight = false;
            } else {
                return false;
            }
        }

        if ($this->state === self::STATE_HALF_OPEN) {
            if ($this->probeInFlight) {
                return false;
            }
            $this->probeInFlight = true;
            return true;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->probeInFlight = false;
        $this->state = self::STATE_CLOSED;
    }

    public function recordFailure(): void
    {
        $this->consecutiveFailures++;
        $this->probeInFlight = false;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->trip();
            return;
        }

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->trip();
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    public function snapshot(): array
    {
        return [
            'state' => $this->state,
            'consecutive_failures' => $this->consecutiveFailures,
            'opened_at' => $this->openedAt,
        ];
    }

    private function trip(): void
    {
        $this->state = self::STATE_OPEN;
        $this->openedAt = $this->clock->monotonic();
    }
}
