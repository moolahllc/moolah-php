<?php

declare(strict_types=1);

namespace Moolah\Poynt\Exception;

/**
 * A terminal payment's outcome is unknown: the API returned 504 TERMINAL_NO_RESPONSE,
 * or the request failed in a way that means it may have reached the terminal (timeout,
 * dropped connection, gateway 502/503/504). $status is 0 when no response was received.
 *
 * The payment may still complete. Call $client->payments->reconcile($e->referenceId)
 * before trying again.
 */
final class TerminalNoResponseException extends MoolahApiException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        public readonly string $referenceId,
        int $status,
        ?string $apiCode = 'TERMINAL_NO_RESPONSE',
        ?string $requestId = null,
        mixed $body = null,
        array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $apiCode, $requestId, $body, $headers, $previous);
    }
}
