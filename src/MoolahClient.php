<?php

declare(strict_types=1);

namespace Moolah\Poynt;

use Moolah\Poynt\Http\CurlTransport;
use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Http\Transport;
use Moolah\Poynt\Resource\Business;
use Moolah\Poynt\Resource\Devices;
use Moolah\Poynt\Resource\Payments;
use Moolah\Poynt\Resource\Tokens;
use Moolah\Poynt\Resource\Transactions;
use Moolah\Poynt\Webhook\Webhooks;

/**
 * Client for the Moolah Poynt API.
 *
 *     $moolah = new MoolahClient(getenv('MOOLAH_API_KEY'));
 *     $txn = $moolah->tokens->charge(amount: 2500, paymentToken: $token, fundingSourceType: FundingSourceType::Card);
 */
final class MoolahClient
{
    public readonly Tokens $tokens;
    public readonly Transactions $transactions;
    public readonly Payments $payments;
    public readonly Devices $devices;
    public readonly Business $business;
    public readonly Webhooks $webhooks;
    /** @internal */
    public readonly HttpCore $core;

    /**
     * @param string $apiKey your Moolah API key (sent as a Bearer token)
     * @param string|null $baseUrl defaults to https://process.moolahpay.cc
     * @param float|null $timeout seconds per attempt, default 120 (must exceed the 90 s terminal wait)
     * @param int|null $maxRetries retries for retry-eligible requests, default 2
     * @param string|null $userAgent appended to the SDK's User-Agent
     * @param Transport|null $transport custom HTTP transport; defaults to ext-curl
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
        ?string $userAgent = null,
        ?Transport $transport = null,
    ) {
        $this->core = new HttpCore($apiKey, $baseUrl, $timeout, $maxRetries, $userAgent, $transport ?? new CurlTransport());
        $this->tokens = new Tokens($this->core);
        $this->transactions = new Transactions($this->core);
        $this->payments = new Payments($this->core);
        $this->devices = new Devices($this->core);
        $this->business = new Business($this->core);
        $this->webhooks = new Webhooks();
    }
}
