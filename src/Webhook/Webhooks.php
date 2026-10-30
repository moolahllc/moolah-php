<?php

declare(strict_types=1);

namespace Moolah\Poynt\Webhook;

use Moolah\Poynt\Exception\WebhookVerificationException;

/**
 * Standard Webhooks verification. Usable without a client:
 * (new Webhooks())->verify($rawBody, getallheaders(), $secret).
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a webhook delivery and return the parsed event.
     *
     * @param string $payload the raw request body exactly as received (file_get_contents('php://input'))
     * @param array<string, string|list<string>> $headers request headers (names matched case-insensitively);
     *        $_SERVER-style keys (HTTP_WEBHOOK_ID) are accepted too
     * @param string $secret the endpoint's signing secret from the Developer Portal (whsec_...)
     * @param int|null $now current Unix time, for tests
     * @throws WebhookVerificationException
     */
    public function verify(string $payload, array $headers, string $secret, int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): WebhookEvent
    {
        $msgId = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($msgId === null || $timestamp === null || $signatures === null) {
            throw new WebhookVerificationException('Missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (!\preg_match('/^\d+$/', $timestamp)) {
            throw new WebhookVerificationException('Invalid webhook-timestamp header');
        }
        $ts = (int) $timestamp;
        $now ??= \time();
        if ($now - $ts > $toleranceSeconds) {
            throw new WebhookVerificationException('Webhook timestamp is too old');
        }
        if ($ts - $now > $toleranceSeconds) {
            throw new WebhookVerificationException('Webhook timestamp is too far in the future');
        }

        $key = self::decodeSecret($secret);
        $expected = \base64_encode(\hash_hmac('sha256', $msgId . '.' . $timestamp . '.' . $payload, $key, true));

        $matched = false;
        foreach (\explode(' ', $signatures) as $part) {
            $pieces = \explode(',', $part, 2);
            if (\count($pieces) !== 2 || $pieces[0] !== 'v1') {
                continue;
            }
            if (\hash_equals($expected, $pieces[1])) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('No matching webhook signature');
        }

        try {
            $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('Webhook payload is not valid JSON', 0, $e);
        }
        if (!\is_array($data) || ($data !== [] && \array_is_list($data))) {
            throw new WebhookVerificationException('Webhook payload is not a JSON object');
        }
        return WebhookEvent::fromArray($data);
    }

    private static function decodeSecret(string $secret): string
    {
        if ($secret === '') {
            throw new WebhookVerificationException('Webhook secret is required');
        }
        $b64 = \str_starts_with($secret, 'whsec_') ? \substr($secret, 6) : $secret;
        $key = \preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $b64) ? \base64_decode($b64, true) : false;
        if ($key === false) {
            throw new WebhookVerificationException('Webhook secret is not valid base64');
        }
        if ($key === '') {
            throw new WebhookVerificationException('Webhook secret is empty');
        }
        return $key;
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $server = 'http_' . \str_replace('-', '_', $name);
        $values = [];
        foreach ($headers as $k => $v) {
            $lk = \strtolower((string) $k);
            if ($lk !== $name && $lk !== $server) {
                continue;
            }
            foreach ((array) $v as $item) {
                if (\is_string($item) && $item !== '') {
                    $values[] = $item;
                }
            }
        }
        return $values === [] ? null : \implode(' ', $values);
    }
}
