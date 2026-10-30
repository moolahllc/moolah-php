<?php

declare(strict_types=1);

namespace Moolah\Poynt\Exception;

/** A webhook delivery failed verification. Respond 4xx and do not process it. */
final class WebhookVerificationException extends MoolahException
{
}
