<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Exception\TerminalNoResponseException;
use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Model\PaymentReconciliation;
use Moolah\Poynt\Model\TerminalPaymentResult;

final class Payments
{
    /** @internal */
    public function __construct(private readonly HttpCore $core)
    {
    }

    /**
     * Push a sale to a terminal and wait (up to ~90 s) for the cardholder.
     * POST /api/payments/{deviceId}.
     *
     * Never retried. If the outcome is unknown this throws TerminalNoResponseException with
     * referenceId (a UUID is generated if you pass none): call reconcile() before trying again.
     *
     * @throws TerminalNoResponseException
     */
    public function terminal(
        string $deviceId,
        int $amount,
        ?string $currency = null,
        ?string $referenceId = null,
        ?string $transactionNumber = null,
        ?string $notes = null,
        ?string $receiptEmailAddress = null,
        ?bool $disableTip = null,
        ?bool $multiTender = null,
        ?bool $nonReferencedCredit = null,
        ?bool $setAskToSaveCard = null,
        ?bool $skipReceiptScreen = null,
        ?bool $skipSignatureScreen = null,
        ?float $timeout = null,
    ): TerminalPaymentResult {
        Support::cents('amount', $amount, true);
        $ref = Support::hasText($referenceId) ? (string) $referenceId : self::uuid4();
        $body = Support::compact([
            'amount' => $amount,
            'currency' => $currency ?? Support::DEFAULT_CURRENCY,
            'referenceId' => $ref,
            'transactionNumber' => $transactionNumber,
            'notes' => $notes,
            'receiptEmailAddress' => $receiptEmailAddress,
            'disableTip' => $disableTip,
            'multiTender' => $multiTender,
            'nonReferencedCredit' => $nonReferencedCredit,
            'setAskToSaveCard' => $setAskToSaveCard,
            'skipReceiptScreen' => $skipReceiptScreen,
            'skipSignatureScreen' => $skipSignatureScreen,
        ]);
        $path = '/api/payments/' . Support::seg(Support::id('deviceId', $deviceId));
        return TerminalPaymentResult::fromArray(Support::obj($this->core->request('POST', $path, body: $body, terminalReferenceId: $ref, timeout: $timeout)));
    }

    /**
     * Did a terminal payment complete? Act on outcome: COMPLETED (do not retry),
     * NOT_COMPLETED (safe to retry), PENDING (ask again later). GET /api/payments/reconcile.
     */
    public function reconcile(string $referenceId, ?float $timeout = null, ?int $maxRetries = null): PaymentReconciliation
    {
        $query = ['referenceId' => Support::id('referenceId', $referenceId)];
        return PaymentReconciliation::fromArray(Support::obj($this->core->request('GET', '/api/payments/reconcile', query: $query, timeout: $timeout, maxRetries: $maxRetries)));
    }

    private static function uuid4(): string
    {
        $b = \random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($b), 4));
    }
}
