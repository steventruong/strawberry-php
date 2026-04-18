<?php

declare(strict_types=1);

namespace Strawberry\Internal;

use Throwable;

/**
 * Core engine. Owns configuration, buffers, redactor, transport, circuit
 * breakers, and the flush loop. One instance per process. The public
 * Strawberry facade delegates to this.
 *
 * PHP has no long-running thread model inside a request, so we flush:
 *   - opportunistically when a channel crosses its flush threshold
 *   - when an explicit flush() is called
 *   - automatically at request end via register_shutdown_function
 */
final class Client
{
    public const VERSION = '1.0.0';

    private const ENDPOINT_EVENTS = '/api/v1/ingest';
    private const ENDPOINT_ERRORS = '/api/v1/errors/ingest';
    private const ENDPOINT_LOGS = '/api/v1/logs';

    private bool $enabled = false;
    private string $apiKey = '';
    private string $host = 'https://app.gotstrawberry.com';
    private string $releaseVersion = '';
    private string $environment = 'production';
    private int $batchSize = 100;
    private float $flushIntervalSeconds = 5.0;
    private float $timeoutSeconds = 5.0;
    private int $maxRetries = 3;
    private bool $shutdownRegistered = false;
    private bool $isShutdown = false;
    private float $lastFlushMonotonic = 0.0;

    private BufferSet $buffers;
    private Redactor $redactor;
    private TransportInterface $transport;
    private ClockInterface $clock;
    private Diagnostics $diagnostics;

    /** @var array<string,CircuitBreaker> */
    private array $breakers;

    /** @var array<string,Backoff> */
    private array $backoffs;

    public function __construct(
        ?TransportInterface $transport = null,
        ?ClockInterface $clock = null,
        ?BufferSet $buffers = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->transport = $transport ?? new Transport();
        $this->buffers = $buffers ?? new BufferSet();
        $this->redactor = new Redactor();
        $this->diagnostics = new Diagnostics($this->clock);

        $this->breakers = [
            self::ENDPOINT_EVENTS => new CircuitBreaker($this->clock),
            self::ENDPOINT_ERRORS => new CircuitBreaker($this->clock),
            self::ENDPOINT_LOGS => new CircuitBreaker($this->clock),
        ];
        $this->backoffs = [
            self::ENDPOINT_EVENTS => new Backoff(),
            self::ENDPOINT_ERRORS => new Backoff(),
            self::ENDPOINT_LOGS => new Backoff(),
        ];

        $this->lastFlushMonotonic = $this->clock->monotonic();
    }

    /**
     * @param array<string,mixed> $opts Supported keys:
     *   host, release_version, environment, batch_size, flush_interval,
     *   timeout, max_retries, register_shutdown (default true)
     */
    public function init(string $apiKey, array $opts = []): void
    {
        if ($apiKey === '') {
            $this->enabled = false;
            return;
        }

        $this->apiKey = $apiKey;

        if (isset($opts['host']) && is_string($opts['host']) && $opts['host'] !== '') {
            $this->host = rtrim($opts['host'], '/');
        }
        if (isset($opts['release_version']) && is_string($opts['release_version'])) {
            $this->releaseVersion = $opts['release_version'];
        }
        if (isset($opts['environment']) && is_string($opts['environment']) && $opts['environment'] !== '') {
            $this->environment = $opts['environment'];
        }
        if (isset($opts['batch_size']) && is_int($opts['batch_size']) && $opts['batch_size'] > 0) {
            $this->batchSize = $opts['batch_size'];
        }
        if (isset($opts['flush_interval']) && is_numeric($opts['flush_interval'])) {
            $this->flushIntervalSeconds = max(0.1, (float) $opts['flush_interval']);
        }
        if (isset($opts['timeout']) && is_numeric($opts['timeout'])) {
            $this->timeoutSeconds = max(0.5, (float) $opts['timeout']);
        }
        if (isset($opts['max_retries']) && is_int($opts['max_retries']) && $opts['max_retries'] >= 0) {
            $this->maxRetries = $opts['max_retries'];
        }

        $this->enabled = true;
        $this->isShutdown = false;

        $registerShutdown = $opts['register_shutdown'] ?? true;
        if ($registerShutdown && !$this->shutdownRegistered) {
            register_shutdown_function(function (): void {
                try {
                    $this->shutdown();
                } catch (Throwable) {
                    // Swallow; observability must never break the host.
                }
            });
            $this->shutdownRegistered = true;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !$this->isShutdown;
    }

    /**
     * @param array<string,mixed> $props
     */
    public function capture(string $event, array $props = [], ?string $distinctId = null): void
    {
        if (!$this->isEnabled() || $event === '') {
            return;
        }
        try {
            $payload = [
                'event' => $event,
                'properties' => $this->redactor->redactArray($props),
                'timestamp' => $this->isoTimestamp(),
            ];
            if ($distinctId !== null && $distinctId !== '') {
                $payload['distinct_id'] = $distinctId;
            }
            if ($this->releaseVersion !== '') {
                $payload['properties']['$release_version'] = $this->releaseVersion;
            }
            $payload['properties']['$environment'] = $this->environment;
            $payload['properties']['$sdk'] = 'strawberry-php';
            $payload['properties']['$sdk_version'] = self::VERSION;

            $this->buffers->push(BufferSet::CH_EVENTS, $payload);
            $this->maybeAutoFlush(BufferSet::CH_EVENTS);
        } catch (Throwable) {
            // Never raise on capture.
        }
    }

    /**
     * @param array<string,mixed> $props
     */
    public function identify(string $distinctId, array $props = []): void
    {
        if (!$this->isEnabled() || $distinctId === '') {
            return;
        }
        try {
            $payload = [
                'event' => '$identify',
                'distinct_id' => $distinctId,
                'properties' => array_merge(
                    $this->redactor->redactArray($props),
                    ['$environment' => $this->environment],
                ),
                'timestamp' => $this->isoTimestamp(),
            ];
            if ($this->releaseVersion !== '') {
                $payload['properties']['$release_version'] = $this->releaseVersion;
            }
            // Identify events ship on the events endpoint, but are buffered
            // separately so we can expose backpressure per channel.
            $this->buffers->push(BufferSet::CH_IDENTIFY, $payload);
            $this->maybeAutoFlush(BufferSet::CH_IDENTIFY);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public function captureError(Throwable|string $err, array $context = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            if ($err instanceof Throwable) {
                $errorType = $err::class;
                $message = $err->getMessage();
                $stack = $err->getTraceAsString();
            } else {
                $errorType = 'Error';
                $message = $err;
                $stack = '';
            }

            $safeContext = $this->redactor->redactArray($context);
            $tags = [];
            if (isset($safeContext['tags']) && is_array($safeContext['tags'])) {
                $tags = $safeContext['tags'];
                unset($safeContext['tags']);
            }
            $sourceMapId = null;
            if (isset($safeContext['source_map_id']) && is_string($safeContext['source_map_id'])) {
                $sourceMapId = $safeContext['source_map_id'];
                unset($safeContext['source_map_id']);
            }

            $payload = [
                'error_type' => $errorType,
                'message' => $this->redactString($message),
                'stack_trace' => $this->redactString($stack),
                'context' => $safeContext,
                'tags' => $tags,
                'release_version' => $this->releaseVersion,
                'source_map_id' => $sourceMapId,
                'environment' => $this->environment,
                'timestamp' => $this->isoTimestamp(),
                'sdk' => 'strawberry-php',
                'sdk_version' => self::VERSION,
            ];
            $this->buffers->push(BufferSet::CH_ERRORS, $payload);
            $this->maybeAutoFlush(BufferSet::CH_ERRORS);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $attrs
     */
    public function log(string $level, string $message, ?string $category = null, array $attrs = []): void
    {
        if (!$this->isEnabled() || $message === '') {
            return;
        }
        try {
            $payload = [
                'level' => $level !== '' ? strtolower($level) : 'info',
                'message' => $this->redactString($message),
                'category' => $category,
                'attrs' => $this->redactor->redactArray($attrs),
                'environment' => $this->environment,
                'release_version' => $this->releaseVersion,
                'timestamp' => $this->isoTimestamp(),
            ];
            $this->buffers->push(BufferSet::CH_LOGS, $payload);
            $this->maybeAutoFlush(BufferSet::CH_LOGS);
        } catch (Throwable) {
            // Never raise.
        }
    }

    /**
     * @param array<string,mixed> $opts
     */
    public function llmCall(string $provider, string $model, array $opts = []): void
    {
        if (!$this->isEnabled() || $provider === '' || $model === '') {
            return;
        }
        try {
            $props = [
                '$llm_provider' => $provider,
                '$llm_model' => $model,
                '$llm_prompt_tokens' => (int) ($opts['promptTokens'] ?? 0),
                '$llm_completion_tokens' => (int) ($opts['completionTokens'] ?? 0),
                '$llm_total_tokens' =>
                    (int) ($opts['promptTokens'] ?? 0) + (int) ($opts['completionTokens'] ?? 0),
                '$llm_status' => (string) ($opts['status'] ?? 'success'),
                '$llm_streaming' => (bool) ($opts['streaming'] ?? false),
            ];
            if (isset($opts['latencyMs']) && is_numeric($opts['latencyMs'])) {
                $props['$llm_latency_ms'] = (float) $opts['latencyMs'];
            }
            if (isset($opts['costUsd']) && is_numeric($opts['costUsd'])) {
                $props['$llm_cost_usd'] = (float) $opts['costUsd'];
            }
            if (isset($opts['errorMessage']) && is_string($opts['errorMessage'])) {
                $props['$llm_error_message'] = $this->redactString($opts['errorMessage']);
            }

            $distinctId = isset($opts['distinctId']) && is_string($opts['distinctId']) && $opts['distinctId'] !== ''
                ? $opts['distinctId']
                : null;

            $payload = [
                'event' => '$llm_call',
                'properties' => array_merge($props, [
                    '$environment' => $this->environment,
                    '$sdk' => 'strawberry-php',
                    '$sdk_version' => self::VERSION,
                ]),
                'timestamp' => $this->isoTimestamp(),
            ];
            if ($this->releaseVersion !== '') {
                $payload['properties']['$release_version'] = $this->releaseVersion;
            }
            if ($distinctId !== null) {
                $payload['distinct_id'] = $distinctId;
            }
            $this->buffers->push(BufferSet::CH_LLM, $payload);
            $this->maybeAutoFlush(BufferSet::CH_LLM);
        } catch (Throwable) {
            // Never raise.
        }
    }

    public function flush(): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $this->flushEvents();
            $this->flushErrors();
            $this->flushLogs();
            $this->lastFlushMonotonic = $this->clock->monotonic();
        } catch (Throwable) {
            // Never raise.
        }
    }

    public function shutdown(): void
    {
        if ($this->isShutdown) {
            return;
        }
        try {
            $this->flush();
        } finally {
            $this->isShutdown = true;
        }
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        $breakerStates = [];
        foreach ($this->breakers as $endpoint => $breaker) {
            $breakerStates[$endpoint] = $breaker->snapshot();
        }
        return array_merge(
            [
                'enabled' => $this->enabled,
                'shutdown' => $this->isShutdown,
                'host' => $this->host,
                'environment' => $this->environment,
                'release_version' => $this->releaseVersion,
                'sdk_version' => self::VERSION,
                'batch_size' => $this->batchSize,
                'flush_interval_seconds' => $this->flushIntervalSeconds,
            ],
            $this->diagnostics->snapshot($this->buffers, $breakerStates)
        );
    }

    // ---------------- internal helpers ----------------

    private function maybeAutoFlush(string $channel): void
    {
        $size = $this->buffers->size($channel);
        if ($size >= $this->batchSize) {
            // Flush only this channel's endpoint to keep the hot path short.
            if ($channel === BufferSet::CH_ERRORS) {
                $this->flushErrors();
            } elseif ($channel === BufferSet::CH_LOGS) {
                $this->flushLogs();
            } else {
                $this->flushEvents();
            }
            return;
        }

        $elapsed = $this->clock->monotonic() - $this->lastFlushMonotonic;
        if ($elapsed >= $this->flushIntervalSeconds) {
            $this->flush();
        }
    }

    private function flushEvents(): void
    {
        // Events + identify + llm share the ingest endpoint. Drain all three.
        $batch = array_merge(
            $this->buffers->drain(BufferSet::CH_EVENTS, $this->batchSize),
            $this->buffers->drain(BufferSet::CH_IDENTIFY, $this->batchSize),
            $this->buffers->drain(BufferSet::CH_LLM, $this->batchSize),
        );
        if ($batch === []) {
            return;
        }
        $body = [
            'api_key' => $this->apiKey,
            'batch' => $batch,
            'sent_at' => $this->isoTimestamp(),
        ];
        $ok = $this->send(self::ENDPOINT_EVENTS, $body);
        if (!$ok) {
            // Requeue events; drop identify/llm overflow if quotas force it.
            $this->buffers->requeueFront(BufferSet::CH_EVENTS, $batch);
        }
    }

    private function flushErrors(): void
    {
        $batch = $this->buffers->drain(BufferSet::CH_ERRORS, $this->batchSize);
        if ($batch === []) {
            return;
        }
        // The errors endpoint accepts a single error per call per the
        // documented payload shape. Send each, but cap per-flush work.
        $requeue = [];
        foreach ($batch as $err) {
            $err['api_key'] = $this->apiKey;
            $ok = $this->send(self::ENDPOINT_ERRORS, $err);
            if (!$ok) {
                $requeue[] = $err;
            }
        }
        if ($requeue !== []) {
            // Strip api_key before requeue (it'll get re-added on retry).
            foreach ($requeue as &$r) {
                unset($r['api_key']);
            }
            unset($r);
            $this->buffers->requeueFront(BufferSet::CH_ERRORS, $requeue);
        }
    }

    private function flushLogs(): void
    {
        $batch = $this->buffers->drain(BufferSet::CH_LOGS, $this->batchSize);
        if ($batch === []) {
            return;
        }
        $body = [
            'api_key' => $this->apiKey,
            'logs' => $batch,
            'sent_at' => $this->isoTimestamp(),
        ];
        $ok = $this->send(self::ENDPOINT_LOGS, $body);
        if (!$ok) {
            $this->buffers->requeueFront(BufferSet::CH_LOGS, $batch);
        }
    }

    /**
     * Send with circuit breaker + retry + backoff. Returns true on success.
     *
     * @param array<string,mixed> $body
     */
    private function send(string $endpointPath, array $body): bool
    {
        $breaker = $this->breakers[$endpointPath] ?? null;
        if ($breaker !== null && !$breaker->allow()) {
            $this->diagnostics->recordFailed($endpointPath, 1);
            return false;
        }

        $url = $this->host . $endpointPath;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->diagnostics->recordFailed($endpointPath, 1);
            $breaker?->recordFailure();
            return false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'X-Strawberry-SDK' => 'php/' . self::VERSION,
        ];

        $attempt = 0;
        $backoff = $this->backoffs[$endpointPath] ?? null;

        while (true) {
            $response = $this->transport->post($url, $headers, $json, $this->timeoutSeconds);
            if ($response->ok()) {
                $breaker?->recordSuccess();
                $backoff?->reset();
                $this->diagnostics->recordSent($endpointPath, 1);
                return true;
            }

            if (!$response->retryable() || $attempt >= $this->maxRetries) {
                $breaker?->recordFailure();
                $this->diagnostics->recordFailed($endpointPath, 1);
                return false;
            }

            $attempt++;
            $this->diagnostics->recordRetry();
            if ($backoff !== null) {
                $this->clock->sleep($backoff->next());
            }
        }
    }

    private function redactString(string $s): string
    {
        $r = $this->redactor->redact($s);
        return is_string($r) ? $r : $s;
    }

    private function isoTimestamp(): string
    {
        $t = $this->clock->now();
        $secs = (int) floor($t);
        $micros = (int) round(($t - $secs) * 1_000_000);
        // RFC3339 with microseconds + Z.
        return gmdate('Y-m-d\TH:i:s', $secs) . '.' . str_pad((string) $micros, 6, '0', STR_PAD_LEFT) . 'Z';
    }
}
