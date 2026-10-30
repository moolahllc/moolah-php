<?php

declare(strict_types=1);

namespace Moolah\Poynt\Exception;

/**
 * The API answered with a non-2xx status.
 *
 * $apiCode is the API's machine-readable code when it sent one, always a string:
 * numeric refund codes become "101" (cash payment), "102" (already voided),
 * "103" (partial refund of an unsettled transaction), "104" (already refunded),
 * "105" (processor rejected); others are strings such as TERMINAL_NO_RESPONSE.
 * (Named apiCode because \Exception::getCode() is reserved for an int.)
 */
class MoolahApiException extends MoolahException
{
    /**
     * @param mixed $body decoded JSON body, or the raw text if it was not JSON
     * @param array<string, string> $headers response headers, lower-cased names
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $apiCode = null,
        public readonly ?string $requestId = null,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
