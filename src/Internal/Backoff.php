<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Jittered decorrelated exponential backoff.
 *
 * Algorithm (AWS "decorrelated jitter"):
 *   delay_{n+1} = min(cap, random_between(base, delay_n * 3))
 *
 * Each instance tracks its own previous delay so multiple endpoints can use
 * independent schedules without interference.
 */
final class Backoff
{
    private float $last;

    public function __construct(
        private readonly float $base = 0.5,
        private readonly float $cap = 30.0,
    ) {
        $this->last = $this->base;
    }

    public function reset(): void
    {
        $this->last = $this->base;
    }

    /** @return float seconds to sleep before next attempt */
    public function next(): float
    {
        $upper = min($this->cap, $this->last * 3.0);
        if ($upper < $this->base) {
            $upper = $this->base;
        }
        $delay = $this->randomBetween($this->base, $upper);
        $this->last = $delay;
        return $delay;
    }

    private function randomBetween(float $lo, float $hi): float
    {
        if ($hi <= $lo) {
            return $lo;
        }
        $r = mt_rand() / mt_getrandmax();
        return $lo + $r * ($hi - $lo);
    }
}
