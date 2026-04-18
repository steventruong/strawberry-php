<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Always-on PII redactor. Walks arbitrary payloads and replaces any detected
 * secret with a stable placeholder. Never throws, never mutates input.
 */
final class Redactor
{
    private const PLACEHOLDER = '[REDACTED]';

    /**
     * Field names whose *values* are always redacted regardless of shape.
     * Keep this list conservative. Matching is case-insensitive and covers
     * exact keys only (no substring), which avoids nuking benign names like
     * "stakeholder" while still catching the obvious things.
     */
    private const DENYLIST_KEYS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'cookie',
        'set-cookie',
        'session',
        'session_id',
        'sessionid',
        'csrf',
        'xsrf',
        'ssn',
        'social_security',
        'credit_card',
        'cc_number',
        'cvv',
        'cvc',
        'pin',
        'private_key',
        'privatekey',
    ];

    /** @var list<array{0:string,1:string}> regex -> replacement */
    private array $patterns;

    public function __construct()
    {
        $this->patterns = [
            // Emails
            ['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', self::PLACEHOLDER],
            // JWTs (three base64url segments separated by dots)
            ['/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/', self::PLACEHOLDER],
            // Known API key prefixes
            ['/\b(?:sk|sbk|berry)_[A-Za-z0-9_\-]{16,}\b/', self::PLACEHOLDER],
            ['/\bwt_live_[A-Za-z0-9]{20,}\b/', self::PLACEHOLDER],
            ['/\bghp_[A-Za-z0-9]{20,}\b/', self::PLACEHOLDER],
            ['/\bAKIA[0-9A-Z]{16}\b/', self::PLACEHOLDER],
            // Phone numbers (loose: +country, 10-15 digits, optional separators)
            ['/(?<!\d)(?:\+?\d{1,3}[\s\-\.]?)?\(?\d{3}\)?[\s\-\.]?\d{3}[\s\-\.]?\d{4}(?!\d)/', self::PLACEHOLDER],
        ];
    }

    /**
     * Redact a scalar or nested structure in place-safe way (returns a copy).
     *
     * @param mixed $value
     * @return mixed
     */
    public function redact(mixed $value): mixed
    {
        return $this->walk($value, 0);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function redactArray(array $payload): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->walk($payload, 0);
        return $out;
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > 32) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->isDenylistedKey($k)) {
                    $out[$k] = self::PLACEHOLDER;
                    continue;
                }
                $out[$k] = $this->walk($v, $depth + 1);
            }
            return $out;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    private function isDenylistedKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::DENYLIST_KEYS as $needle) {
            if ($lower === $needle) {
                return true;
            }
        }
        return false;
    }

    private function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $out = $value;
        foreach ($this->patterns as [$pattern, $replacement]) {
            $replaced = @preg_replace($pattern, $replacement, $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }

        // Luhn-valid credit cards. Run last so we don't double-scan already-
        // redacted substrings.
        $out = $this->redactCreditCards($out);

        return $out;
    }

    private function redactCreditCards(string $value): string
    {
        $pattern = '/(?<!\d)(?:\d[ \-]?){13,19}(?!\d)/';
        $result = @preg_replace_callback(
            $pattern,
            function (array $m): string {
                $raw = $m[0];
                $digits = preg_replace('/\D/', '', $raw) ?? '';
                if (strlen($digits) < 13 || strlen($digits) > 19) {
                    return $raw;
                }
                return $this->luhnValid($digits) ? self::PLACEHOLDER : $raw;
            },
            $value
        );
        return is_string($result) ? $result : $value;
    }

    private function luhnValid(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }
}
