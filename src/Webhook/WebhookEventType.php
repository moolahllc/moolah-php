<?php

declare(strict_types=1);

namespace Moolah\Poynt\Webhook;

/** Event type names as shown in the Developer Portal. */
final class WebhookEventType
{
    public const TRANSACTION_AUTHORIZED = 'transaction.authorized';
    public const TRANSACTION_CAPTURED = 'transaction.captured';
    public const TRANSACTION_REFUNDED = 'transaction.refunded';
    public const TRANSACTION_UPDATED = 'transaction.updated';
    public const TRANSACTION_DECLINED = 'transaction.declined';
    public const TRANSACTION_VOIDED = 'transaction.voided';

    public const ALL = [
        self::TRANSACTION_AUTHORIZED,
        self::TRANSACTION_CAPTURED,
        self::TRANSACTION_REFUNDED,
        self::TRANSACTION_UPDATED,
        self::TRANSACTION_DECLINED,
        self::TRANSACTION_VOIDED,
    ];

    /** TRANSACTION_CAPTURED -> transaction.captured */
    public static function fromPayload(string $eventType): string
    {
        $parts = \explode('_', $eventType, 2);
        return \strtolower(\count($parts) === 2 ? $parts[0] . '.' . $parts[1] : $eventType);
    }
}
