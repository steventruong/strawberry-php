<?php

declare(strict_types=1);

namespace Strawberry;

use Throwable;

/**
 * PSR-15-compatible middleware + a plain callable fallback.
 *
 * Usage with any PSR-15 pipeline:
 *
 *   $pipeline->pipe(new Strawberry\Middleware());
 *
 * Usage without PSR-15 (plain callable):
 *
 *   $handler = Strawberry::middleware();
 *   $response = $handler($request, $next);
 *
 * Always captures:
 *   - $http_request on every request
 *   - $http_error on exception or >=500 response
 *
 * Classes from psr/http-server-middleware are detected at runtime so we can
 * omit the hard dependency. If they're absent, __invoke() is still usable as
 * a plain callable.
 */
final class Middleware
{
    /**
     * PSR-15 process(). Only used when \Psr\Http\Server\MiddlewareInterface exists.
     *
     * @param object $request  \Psr\Http\Message\ServerRequestInterface
     * @param object $handler  \Psr\Http\Server\RequestHandlerInterface
     * @return object          \Psr\Http\Message\ResponseInterface
     */
    public function process(object $request, object $handler): object
    {
        $start = microtime(true);
        $method = $this->extractMethod($request);
        $path = $this->extractPath($request);
        $status = 0;
        $error = null;

        try {
            /** @var object $response */
            $response = $handler->handle($request);
            if (method_exists($response, 'getStatusCode')) {
                /** @psalm-suppress MixedAssignment */
                $status = (int) $response->getStatusCode();
            }
            return $response;
        } catch (Throwable $t) {
            $error = $t;
            $status = 500;
            throw $t;
        } finally {
            $latencyMs = (microtime(true) - $start) * 1000.0;
            $this->recordRequest($method, $path, $status, $latencyMs);
            if ($error !== null) {
                Strawberry::captureError($error, [
                    'http_method' => $method,
                    'http_path' => $path,
                    'http_status' => $status,
                ]);
            } elseif ($status >= 500) {
                Strawberry::capture('$http_error', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'latency_ms' => $latencyMs,
                ]);
            }
        }
    }

    /**
     * Plain-callable variant for non-PSR stacks.
     *
     * @param mixed    $request  Any request object or array the callee accepts.
     * @param callable $next     fn($request): mixed
     * @return mixed
     */
    public function __invoke(mixed $request, callable $next): mixed
    {
        $start = microtime(true);
        $method = is_object($request) ? $this->extractMethod($request) : 'UNKNOWN';
        $path = is_object($request) ? $this->extractPath($request) : '/';
        $status = 0;
        $error = null;

        try {
            $response = $next($request);
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                $status = (int) $response->getStatusCode();
            }
            return $response;
        } catch (Throwable $t) {
            $error = $t;
            $status = 500;
            throw $t;
        } finally {
            $latencyMs = (microtime(true) - $start) * 1000.0;
            $this->recordRequest($method, $path, $status, $latencyMs);
            if ($error !== null) {
                Strawberry::captureError($error, [
                    'http_method' => $method,
                    'http_path' => $path,
                    'http_status' => $status,
                ]);
            } elseif ($status >= 500) {
                Strawberry::capture('$http_error', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'latency_ms' => $latencyMs,
                ]);
            }
        }
    }

    private function recordRequest(string $method, string $path, int $status, float $latencyMs): void
    {
        Strawberry::capture('$http_request', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'latency_ms' => $latencyMs,
        ]);
    }

    private function extractMethod(object $request): string
    {
        if (method_exists($request, 'getMethod')) {
            $m = $request->getMethod();
            return is_string($m) ? strtoupper($m) : 'UNKNOWN';
        }
        return 'UNKNOWN';
    }

    private function extractPath(object $request): string
    {
        if (method_exists($request, 'getUri')) {
            $uri = $request->getUri();
            if (is_object($uri) && method_exists($uri, 'getPath')) {
                $p = $uri->getPath();
                return is_string($p) && $p !== '' ? $p : '/';
            }
        }
        return '/';
    }
}
