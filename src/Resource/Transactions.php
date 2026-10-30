<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Model\RefundResponse;
use Moolah\Poynt\Model\Surcharge;
use Moolah\Poynt\Model\Transaction;
use Moolah\Poynt\Model\TransactionList;

final class Transactions
{
    /** @internal */
    public function __construct(private readonly HttpCore $core)
    {
    }

    /** List transactions. Times are RFC 3339 strings or DateTimeInterface. GET /api/transactions. */
    public function list(
        string|\DateTimeInterface|null $startAt = null,
        string|\DateTimeInterface|null $endAt = null,
        ?int $limit = null,
        ?string $orderBy = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): TransactionList {
        $fmt = static fn ($v) => $v instanceof \DateTimeInterface ? $v->format(\DateTimeInterface::RFC3339) : $v;
        $query = ['startAt' => $fmt($startAt), 'endAt' => $fmt($endAt), 'limit' => $limit, 'orderBy' => $orderBy];
        return TransactionList::fromArray(Support::obj($this->core->request('GET', '/api/transactions', query: $query, timeout: $timeout, maxRetries: $maxRetries)));
    }

    /** GET /api/transactions/{id}. */
    public function get(string $transactionId, ?float $timeout = null, ?int $maxRetries = null): Transaction
    {
        $path = '/api/transactions/' . Support::seg(Support::id('transactionId', $transactionId));
        return Transaction::fromArray(Support::obj($this->core->request('GET', $path, timeout: $timeout, maxRetries: $maxRetries)));
    }

    /** Capture an authorization; omit $amount to capture in full. POST /api/transactions/{id}/capture; never retried. */
    public function capture(string $transactionId, ?int $amount = null, ?float $timeout = null): Transaction
    {
        Support::cents('amount', $amount, false);
        $path = '/api/transactions/' . Support::seg(Support::id('transactionId', $transactionId)) . '/capture';
        return Transaction::fromArray(Support::obj($this->core->request('POST', $path, body: Support::compact(['amount' => $amount]), timeout: $timeout)));
    }

    /**
     * Refund (or void, if unsettled) a transaction; omit $amount for a full refund. Use this
     * for terminal sales. POST /api/v2/transactions/{id}/refund.
     *
     * @param Surcharge|array<string, mixed>|null $surcharge
     */
    public function refund(
        string $transactionId,
        ?int $amount = null,
        ?string $notes = null,
        Surcharge|array|null $surcharge = null,
        ?string $transactionNumber = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): RefundResponse {
        Support::cents('amount', $amount, false);
        $path = '/api/v2/transactions/' . Support::seg(Support::id('transactionId', $transactionId)) . '/refund';
        $body = Support::compact([
            'amount' => $amount,
            'notes' => $notes,
            'surcharge' => Support::surcharge($surcharge),
            'transactionNumber' => $transactionNumber,
        ]);
        return RefundResponse::fromArray(Support::obj($this->core->request('POST', $path, body: $body, retryable: Support::hasText($transactionNumber), timeout: $timeout, maxRetries: $maxRetries)));
    }
}
