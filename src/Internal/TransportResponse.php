<?php

declare(strict_types=1);

namespace Strawberry\Internal;

final class TransportResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * Retryable = network error, 408, 425, 429, or any 5xx. 4xx (other than the
     * above) are permanent and should not be retried.
     */
    public function retryable(): bool
    {
        if ($this->error !== null) {
            return true;
        }
        if ($this->status === 408 || $this->status === 425 || $this->status === 429) {
            return true;
        }
        return $this->status >= 500;
    }
}
