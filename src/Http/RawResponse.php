<?php

declare(strict_types=1);

namespace Moolah\Poynt\Http;

/** @internal */
final class RawResponse
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
