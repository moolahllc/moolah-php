<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Enum\CardOnFileIntent;
use Moolah\Poynt\Enum\FundingSourceType;
use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Model\Surcharge;
use Moolah\Poynt\Model\TokenizeResponse;
use Moolah\Poynt\Model\Transaction;

final class Tokens
{
    /** @internal */
    public function __construct(private readonly HttpCore $core)
    {
    }

    /**
     * Tokenize a Poynt Collect nonce (card or bank account) or a saved card agreement.
     * Exactly one of $nonce or $cardAgreementId. A bank-account nonce also needs
     * $financialInstrumentAgreement. POST /api/token/tokenize; never retried.
     *
     * @param array<string, mixed>|null $financialInstrumentAgreement forwarded to Poynt verbatim
     */
    public function tokenize(
        ?string $nonce = null,
        ?string $cardAgreementId = null,
        ?bool $cardOnFile = null,
        ?bool $saveCardOnFile = null,
        ?array $financialInstrumentAgreement = null,
        ?bool $financialInstrumentOnFile = null,
        ?bool $saveFinancialInstrumentOnFile = null,
        ?float $timeout = null,
    ): TokenizeResponse {
        if (Support::hasText($nonce) === Support::hasText($cardAgreementId)) {
            throw new \InvalidArgumentException('provide exactly one of nonce or cardAgreementId');
        }
        $body = Support::compact([
            'nonce' => $nonce,
            'card' => Support::hasText($cardAgreementId) ? ['cardAgreement' => ['id' => $cardAgreementId]] : null,
            'cardOnFile' => $cardOnFile,
            'saveCardOnFile' => $saveCardOnFile,
            'financialInstrumentAgreement' => $financialInstrumentAgreement === null ? null : (object) $financialInstrumentAgreement,
            'financialInstrumentOnFile' => $financialInstrumentOnFile,
            'saveFinancialInstrumentOnFile' => $saveFinancialInstrumentOnFile,
        ]);
        return TokenizeResponse::fromArray(Support::obj($this->core->request('POST', '/api/token/tokenize', body: $body, timeout: $timeout)));
    }

    /**
     * Charge a payment token on the default terminal. POST /api/v2/token/charge.
     * $amount is in cents. Without $surcharge the API quotes and adds the surcharge on top
     * of $amount when the card is surcharged. Retried only when $transactionNumber is set.
     *
     * @param Surcharge|array<string, mixed>|null $surcharge
     */
    public function charge(
        int $amount,
        ?string $paymentToken = null,
        FundingSourceType|string|null $fundingSourceType = null,
        ?string $currency = null,
        CardOnFileIntent|string|null $intent = null,
        ?bool $mit = null,
        Surcharge|array|null $surcharge = null,
        ?string $transactionNumber = null,
        ?string $email = null,
        ?string $notes = null,
        ?bool $partialAuthEnabled = null,
        ?string $cardToken = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): Transaction {
        $body = Support::chargeBody($amount, $paymentToken, $fundingSourceType, $currency, $intent, $mit, $surcharge, $transactionNumber, $email, $notes, $partialAuthEnabled, $cardToken);
        return Transaction::fromArray(Support::obj($this->core->request('POST', '/api/v2/token/charge', body: $body, retryable: Support::hasText($transactionNumber), timeout: $timeout, maxRetries: $maxRetries)));
    }

    /**
     * Authorize (no capture) a payment token; capture with transactions->capture().
     * POST /api/v2/token/authorize. Retried only when $transactionNumber is set.
     *
     * @param Surcharge|array<string, mixed>|null $surcharge
     */
    public function authorize(
        int $amount,
        ?string $paymentToken = null,
        FundingSourceType|string|null $fundingSourceType = null,
        ?string $currency = null,
        CardOnFileIntent|string|null $intent = null,
        ?bool $mit = null,
        Surcharge|array|null $surcharge = null,
        ?string $transactionNumber = null,
        ?string $email = null,
        ?string $notes = null,
        ?bool $partialAuthEnabled = null,
        ?string $cardToken = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): Transaction {
        $body = Support::chargeBody($amount, $paymentToken, $fundingSourceType, $currency, $intent, $mit, $surcharge, $transactionNumber, $email, $notes, $partialAuthEnabled, $cardToken);
        return Transaction::fromArray(Support::obj($this->core->request('POST', '/api/v2/token/authorize', body: $body, retryable: Support::hasText($transactionNumber), timeout: $timeout, maxRetries: $maxRetries)));
    }

    /**
     * Refund a sale back to its payment token, in full or in part; repeatable against the
     * same $parentId. POST /api/v2/token/refund.
     *
     * @param Surcharge|array<string, mixed>|null $surcharge
     */
    public function refund(
        string $parentId,
        int $amount,
        ?string $paymentToken = null,
        FundingSourceType|string|null $fundingSourceType = null,
        ?string $currency = null,
        CardOnFileIntent|string|null $intent = null,
        ?bool $mit = null,
        Surcharge|array|null $surcharge = null,
        ?string $transactionNumber = null,
        ?string $email = null,
        ?string $notes = null,
        ?bool $partialAuthEnabled = null,
        ?string $cardToken = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
    ): Transaction {
        $body = Support::chargeBody($amount, $paymentToken, $fundingSourceType, $currency, $intent, $mit, $surcharge, $transactionNumber, $email, $notes, $partialAuthEnabled, $cardToken);
        $body['parentId'] = Support::id('parentId', $parentId);
        return Transaction::fromArray(Support::obj($this->core->request('POST', '/api/v2/token/refund', body: $body, retryable: Support::hasText($transactionNumber), timeout: $timeout, maxRetries: $maxRetries)));
    }
}
