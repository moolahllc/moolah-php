<?php

declare(strict_types=1);

namespace Moolah\Poynt\Http;

use Moolah\Poynt\Exception\MoolahApiException;
use Moolah\Poynt\Exception\MoolahConnectionException;
use Moolah\Poynt\Exception\TerminalNoResponseException;
use Moolah\Poynt\Version;

/** @internal */
final class HttpCore
{
    public const DEFAULT_BASE_URL = 'https://process.moolahpay.cc';
    /** Seconds. Terminal payments wait up to 90 s server-side, so the default must be longer. */
    public const DEFAULT_TIMEOUT = 120.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const TERMINAL_NO_RESPONSE = 'TERMINAL_NO_RESPONSE';

    private const RETRYABLE_STATUS = [429, 502, 503, 504];
    private const GATEWAY_STATUS = [502, 503, 504];
    private const MAX_RETRY_AFTER = 60.0;

    public readonly string $baseUrl;
    public readonly float $timeout;
    public readonly int $maxRetries;
    public readonly string $userAgent;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl,
        ?float $timeout,
        ?int $maxRetries,
        ?string $userAgent,
        private readonly Transport $transport,
    ) {
        if (\trim($apiKey) === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->baseUrl = \rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $timeout ?? self::DEFAULT_TIMEOUT;
        $this->maxRetries = \max(0, $maxRetries ?? self::DEFAULT_MAX_RETRIES);
        $ua = 'moolah-poynt-php/' . Version::VERSION . ' php/' . \PHP_VERSION;
        $this->userAgent = ($userAgent !== null && $userAgent !== '') ? $ua . ' ' . $userAgent : $ua;
        $this->sleep = static function (float $seconds): void {
            if ($seconds > 0) {
                \usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return mixed decoded JSON
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        bool $retryable = false,
        ?string $terminalReferenceId = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): mixed {
        $url = $this->baseUrl . $path;
        $query = \array_filter($query, static fn ($v) => $v !== null);
        if ($query !== []) {
            $url .= '?' . \http_build_query(\array_map(static fn ($v) => \is_bool($v) ? ($v ? 'true' : 'false') : $v, $query), '', '&', \PHP_QUERY_RFC3986);
        }
        $payload = $body === null ? null : \json_encode((object) $body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $canRetry = $method === 'GET' || $retryable;
        $retries = $canRetry ? \max(0, $maxRetries ?? $this->maxRetries) : 0;
        $perAttempt = $timeout ?? $this->timeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $perAttempt);
            } catch (MoolahConnectionException $e) {
                if ($attempt < $retries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                if ($terminalReferenceId !== null) {
                    throw new TerminalNoResponseException(
                        \sprintf(
                            '%s the terminal payment result; it may still complete. Reconcile referenceId %s before retrying.',
                            $e->timedOut ? 'Timed out waiting for' : 'Lost the connection before receiving',
                            $terminalReferenceId,
                        ),
                        $terminalReferenceId,
                        0,
                        previous: $e,
                    );
                }
                throw $e;
            }

            if ($response->status >= 200 && $response->status < 300) {
                if ($response->body === '') {
                    return null;
                }
                try {
                    return \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new MoolahApiException('Response body was not valid JSON', $response->status, null, self::requestId($response->headers, null), $response->body, $response->headers, $e);
                }
            }

            $error = self::toApiException($response, $terminalReferenceId);
            if ($attempt < $retries && \in_array($response->status, self::RETRYABLE_STATUS, true) && !$error instanceof TerminalNoResponseException) {
                ($this->sleep)(self::retryAfter($response->headers) ?? self::backoff($attempt));
                continue;
            }
            throw $error;
        }
    }

    /** @internal For tests: replace the sleep function. */
    public function setSleep(\Closure $sleep): void
    {
        $this->sleep = $sleep;
    }

    private static function toApiException(RawResponse $response, ?string $terminalReferenceId): MoolahApiException
    {
        $body = null;
        if ($response->body !== '') {
            try {
                $body = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = $response->body;
            }
        }
        $obj = \is_array($body) && !\array_is_list($body) ? $body : null;

        $code = null;
        if ($obj !== null && \array_key_exists('code', $obj)) {
            $c = $obj['code'];
            if (\is_string($c) && $c !== '') {
                $code = $c;
            } elseif (\is_int($c) && $c !== 0) {
                $code = (string) $c;
            }
        }
        $message = self::pick($obj, 'message') ?? self::pick($obj, 'error') ?? self::pick($obj, 'developerMessage')
            ?? 'HTTP ' . $response->status;
        $requestId = self::requestId($response->headers, $obj);

        if ($code === self::TERMINAL_NO_RESPONSE) {
            $ref = self::pick($obj, 'referenceId') ?? $terminalReferenceId ?? '';
            return new TerminalNoResponseException($message, $ref, $response->status, $code, $requestId, $body, $response->headers);
        }
        if ($terminalReferenceId !== null && $code === null && \in_array($response->status, self::GATEWAY_STATUS, true)) {
            return new TerminalNoResponseException(
                \sprintf('Gateway error %d during a terminal payment; it may still complete. Reconcile referenceId %s before retrying.', $response->status, $terminalReferenceId),
                $terminalReferenceId,
                $response->status,
                self::TERMINAL_NO_RESPONSE,
                $requestId,
                $body,
                $response->headers,
            );
        }
        return new MoolahApiException($message, $response->status, $code, $requestId, $body, $response->headers);
    }

    /** @param array<string, mixed>|null $obj */
    private static function pick(?array $obj, string $key): ?string
    {
        $v = $obj[$key] ?? null;
        return \is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $obj
     */
    private static function requestId(array $headers, ?array $obj): ?string
    {
        return ($headers['x-request-id'] ?? null) ?: (($headers['x-poynt-request-id'] ?? null) ?: self::pick($obj, 'requestId'));
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers): ?float
    {
        $v = $headers['retry-after'] ?? null;
        if ($v === null) {
            return null;
        }
        if (\is_numeric($v)) {
            $secs = (float) $v;
        } else {
            $at = \strtotime($v);
            if ($at === false) {
                return null;
            }
            $secs = $at - \time();
        }
        return \min(\max(0.0, $secs), self::MAX_RETRY_AFTER);
    }

    /** 0.5 s, 1 s, 2 s ... capped at 8 s, with up to 25% jitter. */
    private static function backoff(int $attempt): float
    {
        $base = \min(0.5 * (2 ** $attempt), 8.0);
        return $base * (1 - \mt_rand() / \mt_getrandmax() * 0.25);
    }
}
