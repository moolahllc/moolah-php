<?php

declare(strict_types=1);

namespace Moolah\Poynt\Webhook;

use Moolah\Poynt\Model\Link;

/**
 * A transaction webhook. Deliberately thin: no amounts, card or customer data. Fetch the
 * transaction ($client->transactions->get($event->resourceId)) with the API key for
 * $event->businessId to get them.
 */
final class WebhookEvent
{
    /**
     * @param string $id unique event id; de-duplicate on it (or on the webhook-id header)
     * @param string $eventType as sent: TRANSACTION_CAPTURED, etc.
     * @param string $type Developer Portal form: transaction.captured, etc.
     * @param string $resourceId the transaction id
     * @param list<Link> $links
     * @param array<string, mixed> $raw the parsed payload as received
     */
    public function __construct(
        public readonly string $id,
        public readonly string $eventType,
        public readonly string $type,
        public readonly string $businessId,
        public readonly string $resource,
        public readonly string $resourceId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly array $links,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k): string => \is_string($data[$k] ?? null) ? $data[$k] : '';
        $links = [];
        if (\is_array($data['links'] ?? null)) {
            foreach ($data['links'] as $l) {
                if (\is_array($l)) {
                    $links[] = Link::fromArray($l);
                }
            }
        }
        return new self(
            $s('id'),
            $s('eventType'),
            WebhookEventType::fromPayload($s('eventType')),
            $s('businessId'),
            $s('resource'),
            $s('resourceId'),
            $s('createdAt'),
            $s('updatedAt'),
            $links,
            $data,
        );
    }
}
