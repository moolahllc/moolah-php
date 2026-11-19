<?php

declare(strict_types=1);

namespace Moolah\Poynt\Http;

use Moolah\Poynt\Exception\MoolahConnectionException;

/** ext-curl transport. Redirects are never followed; proxies come from the usual env vars. */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse
    {
        $ch = \curl_init();
        if ($ch === false) {
            throw new MoolahConnectionException('curl_init() failed');
        }
        $responseHeaders = [];
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $timeoutMs = (int) \max(1, \round($timeout * 1000));
        \curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_TIMEOUT_MS => $timeoutMs,
            \CURLOPT_CONNECTTIMEOUT_MS => \min($timeoutMs, 30_000),
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = \explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
                } elseif (\str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                }
                return \strlen($line);
            },
        ]);
        if ($body !== null) {
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }

        $result = \curl_exec($ch);
        if ($result === false) {
            $errno = \curl_errno($ch);
            $error = \curl_error($ch);
            $path = (string) \parse_url($url, \PHP_URL_PATH);
            if ($errno === \CURLE_OPERATION_TIMEDOUT) {
                throw new MoolahConnectionException(\sprintf('Request to %s %s timed out after %g s', $method, $path, $timeout), true);
            }
            throw new MoolahConnectionException(\sprintf('Request to %s %s failed: %s (curl %d)', $method, $path, $error, $errno));
        }
        // No curl_close(): it has been a no-op since PHP 8.0 (the handle is freed when $ch goes out
        // of scope) and is deprecated in 8.5, where calling it raises a deprecation notice.
        $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        return new RawResponse($status, $responseHeaders, \is_string($result) ? $result : '');
    }
}
