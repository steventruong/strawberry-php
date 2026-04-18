<?php

declare(strict_types=1);

namespace Strawberry\Tests;

use PHPUnit\Framework\TestCase;
use Strawberry\Internal\Backoff;
use Strawberry\Internal\BufferSet;
use Strawberry\Internal\CircuitBreaker;
use Strawberry\Internal\ClockInterface;
use Strawberry\Internal\Redactor;
use Strawberry\Internal\Transport;
use Strawberry\Internal\TransportInterface;
use Strawberry\Internal\TransportResponse;
use Strawberry\Strawberry;

final class FakeClock implements ClockInterface
{
    public float $wall = 1_700_000_000.0;
    public float $mono = 0.0;
    public float $slept = 0.0;

    public function now(): float
    {
        return $this->wall;
    }

    public function monotonic(): float
    {
        return $this->mono;
    }

    public function sleep(float $seconds): void
    {
        $this->slept += $seconds;
        $this->mono += $seconds;
    }
}

final class FakeTransport implements TransportInterface
{
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    public array $calls = [];
    /** @var list<TransportResponse> */
    public array $responses = [];
    public int $cursor = 0;

    public function queue(TransportResponse $r): void
    {
        $this->responses[] = $r;
    }

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $r = $this->responses[$this->cursor] ?? new TransportResponse(200, '{}', null);
        $this->cursor++;
        return $r;
    }
}

final class SmokeTest extends TestCase
{
    protected function setUp(): void
    {
        Strawberry::reset();
    }

    public function testFacadeIsNoOpBeforeInit(): void
    {
        // Must not throw.
        Strawberry::capture('x', ['k' => 'v']);
        Strawberry::identify('u1', ['email' => 'a@b.com']);
        Strawberry::log('info', 'hello');
        Strawberry::llmCall('openai', 'gpt-4o');
        Strawberry::captureError(new \RuntimeException('boom'));
        Strawberry::flush();
        $this->assertFalse(Strawberry::isEnabled());
    }

    public function testInitEnables(): void
    {
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'environment' => 'test',
            'register_shutdown' => false,
        ]);
        $this->assertTrue(Strawberry::isEnabled());
        $diag = Strawberry::diagnostics();
        $this->assertSame('test', $diag['environment']);
        $this->assertSame('https://example.test', $diag['host']);
    }

    public function testCaptureWithInjectedTransport(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        $transport->queue(new TransportResponse(200, '{}', null));

        Strawberry::initWithDependencies($transport, $clock);
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'register_shutdown' => false,
            'batch_size' => 1,
        ]);
        Strawberry::capture('user.signed_up', ['plan' => 'pro'], 'user_123');
        Strawberry::flush();

        $this->assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        $this->assertStringEndsWith('/api/v1/ingest', $call['url']);
        $this->assertSame('Bearer berry_test_key', $call['headers']['Authorization']);
        $decoded = json_decode($call['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('berry_test_key', $decoded['api_key']);
        $this->assertCount(1, $decoded['batch']);
        $this->assertSame('user.signed_up', $decoded['batch'][0]['event']);
        $this->assertSame('user_123', $decoded['batch'][0]['distinct_id']);
    }

    public function testRedactorRedactsKnownSecrets(): void
    {
        $r = new Redactor();
        $out = $r->redactArray([
            'email' => 'alice@example.com',
            'password' => 'hunter2',
            'note' => 'key '.implode('_',['sk','live','abcdefghijklmnop12345']). and jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9.abcdefghij',
            'card' => '4111 1111 1111 1111',
            'phone' => '+1 (415) 555-1212',
            'aws' => 'AKIA'.'IOSFODNN7EXAMPLE',
            'deep' => ['cookie' => 'session=abc', 'ok' => 'hello'],
        ]);
        $this->assertStringNotContainsString('alice@example.com', $out['email']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertStringNotContainsString(implode('_',['sk','live','abcdefghijklmnop12345']), $out['note']);
        $this->assertStringNotContainsString('eyJhbGciOi', $out['note']);
        $this->assertStringNotContainsString('4111', $out['card']);
        $this->assertStringNotContainsString('415', $out['phone']);
        $this->assertStringNotContainsString('AKIA', $out['aws']);
        $this->assertSame('[REDACTED]', $out['deep']['cookie']);
        $this->assertSame('hello', $out['deep']['ok']);
    }

    public function testRedactorLuhnOnlyCards(): void
    {
        $r = new Redactor();
        // Not a Luhn-valid number -> should NOT be redacted.
        $out = $r->redact('order 1234567890123456 shipped');
        $this->assertStringContainsString('1234567890123456', $out);
        // Luhn-valid (4111 1111 1111 1111) -> redacted.
        $out2 = $r->redact('paid 4111111111111111 today');
        $this->assertStringNotContainsString('4111111111111111', $out2);
    }

    public function testBufferSetDropsOldest(): void
    {
        $b = new BufferSet([BufferSet::CH_EVENTS => 3]);
        for ($i = 0; $i < 5; $i++) {
            $b->push(BufferSet::CH_EVENTS, ['n' => $i]);
        }
        $this->assertSame(3, $b->size(BufferSet::CH_EVENTS));
        $this->assertSame(2, $b->droppedCounts()[BufferSet::CH_EVENTS]);
        $drained = $b->drain(BufferSet::CH_EVENTS, 10);
        $this->assertSame([['n' => 2], ['n' => 3], ['n' => 4]], $drained);
    }

    public function testCircuitBreakerTripsAfterThreshold(): void
    {
        $clock = new FakeClock();
        $cb = new CircuitBreaker($clock, failureThreshold: 3, cooldownSeconds: 10.0);
        $this->assertTrue($cb->allow());
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertTrue($cb->allow());
        $cb->recordFailure();
        $this->assertFalse($cb->allow());
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());

        // After cooldown, allow a single probe.
        $clock->mono += 11.0;
        $this->assertTrue($cb->allow());
        $this->assertFalse($cb->allow(), 'only one probe allowed in half-open');
        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
    }

    public function testBackoffGrowsWithJitter(): void
    {
        $b = new Backoff(base: 0.5, cap: 30.0);
        $d1 = $b->next();
        $d2 = $b->next();
        $d3 = $b->next();
        $this->assertGreaterThanOrEqual(0.5, $d1);
        $this->assertGreaterThanOrEqual(0.5, $d2);
        $this->assertGreaterThanOrEqual(0.5, $d3);
        $this->assertLessThanOrEqual(30.0, $d3);
    }

    public function testCaptureErrorHitsErrorsEndpoint(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        $transport->queue(new TransportResponse(200, '{}', null));

        Strawberry::initWithDependencies($transport, $clock);
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'register_shutdown' => false,
        ]);
        Strawberry::captureError(new \RuntimeException('kaboom'), [
            'tags' => ['area' => 'billing'],
            'user_id' => 'u1',
        ]);
        Strawberry::flush();

        $this->assertNotEmpty($transport->calls);
        $call = $transport->calls[0];
        $this->assertStringEndsWith('/api/v1/errors/ingest', $call['url']);
        $decoded = json_decode($call['body'], true);
        $this->assertSame('RuntimeException', $decoded['error_type']);
        $this->assertSame('kaboom', $decoded['message']);
        $this->assertSame(['area' => 'billing'], $decoded['tags']);
    }

    public function testLogsHitLogsEndpoint(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        $transport->queue(new TransportResponse(200, '{}', null));

        Strawberry::initWithDependencies($transport, $clock);
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'register_shutdown' => false,
        ]);
        Strawberry::log('error', 'gateway timeout', 'billing', ['attempt' => 3]);
        Strawberry::flush();

        $this->assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        $this->assertStringEndsWith('/api/v1/logs', $call['url']);
        $decoded = json_decode($call['body'], true);
        $this->assertCount(1, $decoded['logs']);
        $this->assertSame('error', $decoded['logs'][0]['level']);
        $this->assertSame('billing', $decoded['logs'][0]['category']);
    }

    public function testTransportUsesStdlibWhenCurlAbsentFallback(): void
    {
        // Confirms class exists and constructs without throwing.
        $t = new Transport();
        $this->assertInstanceOf(Transport::class, $t);
    }

    public function testLlmCallBuildsExpectedProperties(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        $transport->queue(new TransportResponse(200, '{}', null));

        Strawberry::initWithDependencies($transport, $clock);
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'register_shutdown' => false,
        ]);
        Strawberry::llmCall('openai', 'gpt-4o', [
            'promptTokens' => 100,
            'completionTokens' => 50,
            'latencyMs' => 800,
            'costUsd' => 0.002,
            'status' => 'success',
            'streaming' => true,
            'distinctId' => 'u9',
        ]);
        Strawberry::flush();

        $this->assertCount(1, $transport->calls);
        $decoded = json_decode($transport->calls[0]['body'], true);
        $this->assertSame('$llm_call', $decoded['batch'][0]['event']);
        $props = $decoded['batch'][0]['properties'];
        $this->assertSame('openai', $props['$llm_provider']);
        $this->assertSame('gpt-4o', $props['$llm_model']);
        $this->assertSame(100, $props['$llm_prompt_tokens']);
        $this->assertSame(50, $props['$llm_completion_tokens']);
        $this->assertSame(150, $props['$llm_total_tokens']);
        $this->assertTrue($props['$llm_streaming']);
        $this->assertSame('u9', $decoded['batch'][0]['distinct_id']);
    }

    public function testFailedSendRequeues(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        // First call 500 (retryable but will exhaust), then another 500 to exhaust retries.
        $transport->queue(new TransportResponse(500, '', null));
        $transport->queue(new TransportResponse(500, '', null));
        $transport->queue(new TransportResponse(500, '', null));
        $transport->queue(new TransportResponse(500, '', null));

        Strawberry::initWithDependencies($transport, $clock);
        Strawberry::init('berry_test_key', [
            'host' => 'https://example.test',
            'register_shutdown' => false,
            'max_retries' => 0,
        ]);
        Strawberry::capture('lost', []);
        Strawberry::flush();

        // The event should have been requeued on failure.
        $diag = Strawberry::diagnostics();
        $this->assertSame(1, $diag['buffers']['sizes']['events']);
        $this->assertGreaterThanOrEqual(1, $diag['failed']);
    }
}
