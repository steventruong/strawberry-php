<?php

declare(strict_types=1);

namespace Strawberry\Internal;

/**
 * Default transport. Prefers cURL when the extension is available; falls back
 * to file_get_contents with a stream context. Never throws.
 */
final class Transport implements TransportInterface
{
    public function __construct(
        private readonly string $userAgent = 'strawberry-php/1.0',
    ) {
    }

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        if (function_exists('curl_init')) {
            return $this->postCurl($url, $headers, $body, $timeoutSeconds);
        }
        return $this->postStream($url, $headers, $body, $timeoutSeconds);
    }

    /**
     * @param array<string,string> $headers
     */
    private function postCurl(string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            return new TransportResponse(0, '', 'curl_init_failed');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) max(1000, $timeoutSeconds * 1000));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) max(1000, $timeoutSeconds * 1000));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $responseBody === false) {
            return new TransportResponse(
                $status,
                '',
                $errstr !== '' ? $errstr : ('curl_errno=' . $errno)
            );
        }

        return new TransportResponse($status, (string) $responseBody, null);
    }

    /**
     * @param array<string,string> $headers
     */
    private function postStream(string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        $headerLines = ['Connection: close'];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'user_agent' => $this->userAgent,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = null;
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $err = error_get_last();
            return new TransportResponse(0, '', $err['message'] ?? 'stream_failed');
        }

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        return new TransportResponse($status, (string) $result, null);
    }
}
