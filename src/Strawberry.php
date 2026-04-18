<?php

declare(strict_types=1);

namespace Strawberry;

use Strawberry\Internal\BufferSet;
use Strawberry\Internal\Client;
use Strawberry\Internal\ClockInterface;
use Strawberry\Internal\TransportInterface;
use Throwable;

/**
 * Public facade. Singleton per process.
 *
 *   Strawberry::init('sk_live_...', ['host' => 'https://app.gotstrawberry.com']);
 *   Strawberry::capture('user.signed_up', ['plan' => 'pro'], 'user_123');
 *   Strawberry::identify('user_123', ['email' => 'a@b.com']);
 *   Strawberry::captureError($exception, ['route' => '/checkout']);
 *   Strawberry::log('error', 'payment gateway timeout', 'billing');
 *   Strawberry::llmCall('openai', 'gpt-4o', [
 *       'promptTokens' => 512, 'completionTokens' => 120,
 *       'latencyMs' => 1234, 'costUsd' => 0.004,
 *   ]);
 *   Strawberry::flush();
 *
 * All methods are safe to call before init() (they become no-ops) and never
 * raise to the host application.
 */
final class Strawberry
{
    private static ?Client $client = null;

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $opts
     */
    public static function init(string $apiKey, array $opts = []): void
    {
        try {
            self::client()->init($apiKey, $opts);
        } catch (Throwable) {
            // Swallow; never break the host.
        }
    }

    /** Exposed for tests: inject custom transport/clock/buffers. */
    public static function initWithDependencies(
        ?TransportInterface $transport = null,
        ?ClockInterface $clock = null,
        ?BufferSet $buffers = null,
    ): void {
        self::$client = new Client($transport, $clock, $buffers);
    }

    /** Reset the global singleton. Mainly for tests. */
    public static function reset(): void
    {
        self::$client = null;
    }

    /**
     * @param array<string,mixed> $props
     */
    public static function capture(string $event, array $props = [], ?string $distinctId = null): void
    {
        try {
            self::client()->capture($event, $props, $distinctId);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $props
     */
    public static function identify(string $distinctId, array $props = []): void
    {
        try {
            self::client()->identify($distinctId, $props);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function captureError(Throwable|string $err, array $context = []): void
    {
        try {
            self::client()->captureError($err, $context);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $attrs
     */
    public static function log(string $level, string $message, ?string $category = null, array $attrs = []): void
    {
        try {
            self::client()->log($level, $message, $category, $attrs);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $opts
     */
    public static function llmCall(string $provider, string $model, array $opts = []): void
    {
        try {
            self::client()->llmCall($provider, $model, $opts);
        } catch (Throwable) {
            // Never raise.
        }
    }

    public static function flush(): void
    {
        try {
            self::client()->flush();
        } catch (Throwable) {
            // Never raise.
        }
    }

    public static function shutdown(): void
    {
        try {
            self::client()->shutdown();
        } catch (Throwable) {
            // Never raise.
        }
    }

    public static function isEnabled(): bool
    {
        try {
            return self::client()->isEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public static function diagnostics(): array
    {
        try {
            return self::client()->diagnostics();
        } catch (Throwable) {
            return ['enabled' => false, 'error' => 'diagnostics_failed'];
        }
    }

    /**
     * Returns a PSR-15-compatible middleware instance that is also usable
     * as a plain callable (__invoke) for non-PSR stacks.
     */
    public static function middleware(): Middleware
    {
        return new Middleware();
    }

    private static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client();
        }
        return self::$client;
    }
}
