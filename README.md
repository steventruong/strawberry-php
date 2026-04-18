# Strawberry PHP SDK

Analytics, error tracking, logs, and LLM call capture for PHP servers.

- PHP 8.1+
- Zero runtime dependencies (stdlib only, cURL with `file_get_contents` fallback)
- PSR-4 autoload, PSR-12 style
- Never raises into the host application
- Always-on PII redaction (email, phone, Luhn-valid cards, JWTs, API-key prefixes, denylisted field names)

## Install

Register the VCS repo with Composer, then require the package:

```bash
composer config repositories.strawberry vcs https://github.com/steventruong/strawberry-php
composer require strawberry/sdk:v1.0.0
```

Or by hand in `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/steventruong/strawberry-php" }
    ],
    "require": {
        "strawberry/sdk": "v1.0.0"
    }
}
```

## Quick start

```php
use Strawberry\Strawberry;

Strawberry::init('berry_your_api_key', [
    'host' => 'https://straw.berryagents.com',
    'environment' => 'production',
    'release_version' => '2026.04.17',
    'batch_size' => 100,
    'flush_interval' => 5.0,
]);

Strawberry::capture('user.signed_up', ['plan' => 'pro'], 'user_123');
Strawberry::identify('user_123', ['email' => 'alice@example.com']);
Strawberry::log('error', 'payment gateway timeout', 'billing', ['attempt' => 3]);
Strawberry::llmCall('openai', 'gpt-4o', [
    'promptTokens' => 512,
    'completionTokens' => 120,
    'latencyMs' => 1234,
    'costUsd' => 0.004,
    'status' => 'success',
]);

try {
    riskyThing();
} catch (\Throwable $e) {
    Strawberry::captureError($e, ['route' => '/checkout']);
}
```

The SDK registers a `shutdown_function` automatically on `init()`, so pending events are flushed at request end. You can also call `Strawberry::flush()` or `Strawberry::shutdown()` explicitly.

## Middleware

Works as PSR-15 middleware when `psr/http-server-middleware` is in the app, or as a plain callable otherwise.

```php
$pipeline->pipe(Strawberry::middleware());

// or, outside PSR-15:
$mw = Strawberry::middleware();
$response = $mw($request, fn($req) => $handler->handle($req));
```

Automatically captures `$http_request` on every request, `$http_error` on 5xx, and forwards uncaught exceptions to `captureError`.

## Architecture

| Class | Responsibility |
|---|---|
| `Strawberry\Strawberry` | Public static facade. |
| `Strawberry\Internal\Client` | Core engine. Owns init, capture, flush. |
| `Strawberry\Internal\BufferSet` | Per-channel bounded buffers (events, errors, logs, identify, llm). Drop-oldest. |
| `Strawberry\Internal\Transport` | cURL-first HTTP, `file_get_contents` fallback. Never throws. |
| `Strawberry\Internal\Redactor` | PII scrubbing (always on). |
| `Strawberry\Internal\CircuitBreaker` | Per-endpoint CLOSED/OPEN/HALF_OPEN. |
| `Strawberry\Internal\Backoff` | Decorrelated jittered exponential backoff. |
| `Strawberry\Internal\Diagnostics` | Counters for sent/failed/dropped. |

## Endpoints

- `POST /api/v1/ingest` for analytics events, identify, and LLM calls (batched).
- `POST /api/v1/errors/ingest` for errors.
- `POST /api/v1/logs` for log entries.

Authorization uses the `Authorization: Bearer <api_key>` header and includes an `api_key` body field as a fallback.

## Diagnostics

```php
print_r(Strawberry::diagnostics());
```

Returns buffer sizes, drop counts, send/fail counts per endpoint, and circuit breaker states.

## Testing

Inject a fake transport or clock via `Strawberry::initWithDependencies(...)` before calling `init()`:

```php
Strawberry::initWithDependencies($fakeTransport, $fakeClock);
Strawberry::init('berry_test_key', ['register_shutdown' => false]);
```

Run:

```bash
vendor/bin/phpunit tests/
```
