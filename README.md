# moolah/poynt

Official PHP client for the Moolah Poynt API: saved-card and ACH charges,
terminal payments, refunds, transactions and webhook verification.

- PHP 8.1 or later with `ext-curl` and `ext-json`. No other dependencies (no Guzzle).
- Typed: named arguments, enums, and response model classes with readonly properties.
- All amounts are **integer cents** (`2500` = $25.00).

## Install

```sh
composer require moolah/poynt
```

## Quickstart: tokenize, charge, refund

```php
use Moolah\Poynt\MoolahClient;
use Moolah\Poynt\Enum\FundingSourceType;

$moolah = new MoolahClient(getenv('MOOLAH_API_KEY'));

// 1. Turn a Poynt Collect nonce from your payment form into a reusable token.
$token = $moolah->tokens->tokenize(nonce: $nonce, saveCardOnFile: true)->paymentToken;

// 2. Charge it. If the card is surcharged and you send no surcharge,
//    the API quotes it and charges it on top of $amount.
$sale = $moolah->tokens->charge(
    amount: 2500,
    paymentToken: $token,
    fundingSourceType: FundingSourceType::Card,
    transactionNumber: 'INV-1001',
);

// 3. Refund part of it (repeatable against the same parentId).
$moolah->tokens->refund(
    parentId: $sale->id,
    amount: 1000,
    paymentToken: $token,
    fundingSourceType: FundingSourceType::Card,
);
```

`fundingSourceType` is required whenever you send `paymentToken`:
`FundingSourceType::Card` or `FundingSourceType::Ach` (`CHEQUE`). The SDK enforces this
because the API treats a bare `paymentToken` as ACH.

Use `$sale->processorResponse?->approvedAmount` for what was actually approved (it can be
lower than `$sale->amounts?->orderAmount` on a partial approval). Every model also has
`toArray()` with the response exactly as received.

`$amount` is an `int`. Under `declare(strict_types=1)` a float is a `TypeError`; without
it PHP truncates fractional floats with a deprecation notice, so always pass cents.

## Card on file: `intent` and `mit`

Stored-card charges are classified for the card networks. Both are optional; the API
defaults to `UNSCHEDULED_COF_TXN` and merchant-initiated.

```php
use Moolah\Poynt\Enum\CardOnFileIntent;

$moolah->tokens->charge(
    amount: 4900,
    paymentToken: $token,
    fundingSourceType: FundingSourceType::Card,
    intent: CardOnFileIntent::Recurring, // or Installment, Unscheduled
    mit: true, // merchant-initiated; false when the cardholder is present and approving
);
```

| `intent` | Use for |
|---|---|
| `UNSCHEDULED_COF_TXN` | Stored card charged at no fixed schedule (default) |
| `RECURRING_COF_TXN` | Fixed-schedule charges, e.g. a monthly membership plan |
| `INSTALLMENT_COF_TXN` | One of a fixed number of payments for one purchase, e.g. a treatment plan |

`mit` is only sent when you pass it, so an explicit `mit: false` reaches the API.

Tokenize a saved card by agreement instead of a nonce with
`$moolah->tokens->tokenize(cardAgreementId: '...')`.

ACH: check `$moolah->business->achStatus()->allowAch` first; tokenizing a bank account needs
`financialInstrumentAgreement` (the authorization the customer accepted).

## Terminal payments and reconciliation

`payments->terminal()` pushes a sale to a Poynt terminal and waits (up to about 90 seconds)
for the cardholder. If the outcome is unknown it throws `TerminalNoResponseException`.
**Do not retry blindly**: the payment may still complete on the terminal. Reconcile first.

```php
use Moolah\Poynt\Enum\ReconcileOutcome;
use Moolah\Poynt\Exception\TerminalNoResponseException;

try {
    $result = $moolah->payments->terminal($deviceId, amount: 7500, referenceId: 'visit-8812');
} catch (TerminalNoResponseException $e) {
    $rec = $moolah->payments->reconcile($e->referenceId);
    match (ReconcileOutcome::tryFrom((string) $rec->outcome)) {
        ReconcileOutcome::Completed => recordPayment($rec->transactions), // it happened: do not retry
        ReconcileOutcome::NotCompleted => offerRetry(),                    // safe to try again
        default => checkAgainLater($e->referenceId),                      // PENDING: a retry may double-charge
    };
    // $rec->matchedBy === 'TIME_WINDOW' means no result was received and $rec->transactions
    // is the terminal's activity around that time. Check it before deciding.
}
```

- Pass your own `referenceId` (otherwise the SDK generates a UUID) so every attempt can be reconciled.
- `TerminalNoResponseException` covers the API's `504 TERMINAL_NO_RESPONSE` and also a timeout,
  dropped connection or gateway 502/503/504 during a terminal payment (`status` is 0 when no
  response was received).
- Terminal payments are never retried automatically.
- List terminals with `$moolah->devices->list()`.

Refund a terminal sale with `$moolah->transactions->refund($transactionId, amount: ...)`
(omit `amount` for a full refund or void).

## Transactions

```php
$page = $moolah->transactions->list(startAt: new DateTimeImmutable('2026-10-01T00:00:00Z'), limit: 50, orderBy: 'desc');
$txn  = $moolah->transactions->get($id);
$moolah->transactions->capture($authId);               // full capture
$moolah->transactions->capture($authId, amount: 1500); // partial
$moolah->transactions->refund($id, amount: 500);       // partial refund
```

## Webhooks

Endpoints and their signing secrets are managed in the Moolah Developer Portal (there is no
API for registering them). Verify every delivery against the **raw** request body:

```php
use Moolah\Poynt\Exception\WebhookVerificationException;
use Moolah\Poynt\Webhook\Webhooks;

try {
    $event = (new Webhooks())->verify(
        file_get_contents('php://input'),
        getallheaders(),            // or $_SERVER, or your framework's header array
        getenv('MOOLAH_WEBHOOK_SECRET'), // "whsec_..."
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}
http_response_code(204); // respond within 15 s; de-duplicate on $event->id; process asynchronously
```

The event is thin (no amounts or card data):

```php
$event->type;       // "transaction.captured" (also authorized, refunded, updated, declined, voided)
$event->eventType;  // "TRANSACTION_CAPTURED", as sent
$event->businessId; // which business; one endpoint receives events for all of your businesses
$event->resourceId; // the transaction id: fetch it with the API key for that business
```

Verification follows Standard Webhooks: HMAC-SHA256 over `{webhook-id}.{webhook-timestamp}.{body}`,
any `v1,` signature in `webhook-signature` may match (secret rotation), constant-time comparison
(`hash_equals`), and timestamps more than 5 minutes from your clock are rejected
(`toleranceSeconds:` to change). `$moolah->webhooks->verify(...)` is the same method.

## Errors

| Exception | When |
|---|---|
| `MoolahApiException` | Non-2xx response. `$status`, `$apiCode` (string, if the API sent one), `getMessage()`, `$requestId`, `$body`. |
| `TerminalNoResponseException` | Terminal payment outcome unknown. Adds `$referenceId`. Subclass of `MoolahApiException`. |
| `MoolahConnectionException` | Network failure or timeout (`$timedOut`). |
| `WebhookVerificationException` | Webhook failed verification. |
| `\InvalidArgumentException` | Invalid arguments, thrown before any request. |

All SDK exceptions extend `MoolahException` (a `\RuntimeException`). The API code is
`$apiCode`, not `getCode()`, because PHP reserves `getCode()` for integers. Refund failures
carry numeric API codes as strings: `"101"` cash payment, `"102"` already voided, `"103"`
partial refund of an unsettled transaction (void it in full instead), `"104"` already
refunded, `"105"` processor rejected.

`$requestId` comes from the `X-Request-Id` (or `X-Poynt-Request-Id`) response header; include
it when contacting support.

## Retries

The client retries up to `maxRetries` times (default 2) with exponential backoff, honouring
`Retry-After`, on `429`, `502`, `503`, `504` and network errors, but only for requests that are
safe to repeat:

- `GET` requests: always.
- Charge, authorize and refund calls: only when you pass `transactionNumber`.
- Tokenize, capture and terminal payments: never.

`transactionNumber` is your reference for the charge. The API does not document server-side
de-duplication on it, so a retried charge can, in rare cases, be processed twice: pass
`maxRetries: 0` if you would rather handle every failure yourself.

## Configuration

```php
new MoolahClient(
    $apiKey,
    baseUrl: 'https://process.moolahpay.cc', // default
    timeout: 120.0,     // seconds per attempt; must exceed the 90 s terminal wait
    maxRetries: 2,
    userAgent: 'my-practice-app/1.4', // appended to the SDK's User-Agent
    transport: null,    // a Moolah\Poynt\Http\Transport to use your own HTTP stack
);
```

Methods also accept `timeout:` and (where retries apply) `maxRetries:`. Redirects are never
followed. curl takes proxies from the standard `HTTPS_PROXY`/`NO_PROXY` environment variables.

## Development

```sh
php tests/run.php   # starts a local fake API with `php -S`; no Composer packages needed
composer lint
```

`src/Model/*` is generated from `../spec/openapi-public.json` by `../spec/tools/gen_models.py`;
do not edit it by hand.
