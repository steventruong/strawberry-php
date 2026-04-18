<?php

declare(strict_types=1);

namespace Strawberry\Internal;

final class SystemClock implements ClockInterface
{
    public function now(): float
    {
        return microtime(true);
    }

    public function monotonic(): float
    {
        // hrtime returns nanoseconds since an arbitrary epoch, monotonic.
        return hrtime(true) / 1_000_000_000.0;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $micros = (int) round($seconds * 1_000_000);
        if ($micros > 0) {
            usleep($micros);
        }
    }
}
