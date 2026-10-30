<?php

declare(strict_types=1);

namespace Moolah\Poynt\Exception;

/** The request could not be completed: DNS/connection failure, reset or timeout. */
final class MoolahConnectionException extends MoolahException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
