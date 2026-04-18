<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * HTTP transport contract. Must never throw; always return a TransportResponse.
 * Tests may inject a fake implementation to capture requests.
 */
interface TransportInterface
{
    /**
     * @param array<string,string> $headers
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse;
}
