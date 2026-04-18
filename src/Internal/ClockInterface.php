<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Minimal monotonic + wall-clock surface. Injectable so tests can freeze time.
 */
interface ClockInterface
{
    /** Wall-clock seconds, float precision. */
    public function now(): float;

    /** Monotonic seconds, suitable for measuring intervals. */
    public function monotonic(): float;

    /** Sleep for the given number of seconds (may be fractional). */
    public function sleep(float $seconds): void;
}
