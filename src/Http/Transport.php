<?php

declare(strict_types=1);

namespace Moolah\Poynt\Http;

use Moolah\Poynt\Exception\MoolahConnectionException;

/**
 * Sends one HTTP request. Implement it to route the SDK through your own HTTP stack;
 * the default is CurlTransport.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout seconds for the whole request
     * @throws MoolahConnectionException when no HTTP response was received
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse;
}
