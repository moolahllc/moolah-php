<?php

// Test runner for the PHP SDK. No PHPUnit: Packagist is not always reachable from
// where these run, and the suite needs nothing beyond PHP + ext-curl.
//   php tests/run.php

declare(strict_types=1);

namespace Moolah\Poynt\Tests;

use Moolah\Poynt\Enum\CardOnFileIntent;
use Moolah\Poynt\Enum\FundingSourceType;
use Moolah\Poynt\Exception\MoolahApiException;
use Moolah\Poynt\Exception\MoolahConnectionException;
use Moolah\Poynt\Exception\TerminalNoResponseException;
use Moolah\Poynt\Exception\WebhookVerificationException;
use Moolah\Poynt\Http\RawResponse;
use Moolah\Poynt\Http\Transport;
use Moolah\Poynt\Model\Device;
use Moolah\Poynt\Model\Transaction;
use Moolah\Poynt\MoolahClient;
use Moolah\Poynt\Version;
use Moolah\Poynt\Webhook\Webhooks;
use Moolah\Poynt\Webhook\WebhookEventType;

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // @-suppressed
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        foreach (['Moolah\\Poynt\\Tests\\' => __DIR__ . '/', 'Moolah\\Poynt\\' => __DIR__ . '/../src/'] as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

// ---------------------------------------------------------------- harness

final class T
{
    public static int $pass = 0;
    /** @var list<string> */
    public static array $failures = [];

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$pass++;
            echo "  ok   $name\n";
        } catch (\Throwable $e) {
            self::$failures[] = $name;
            echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \AssertionError(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(bool $cond, string $msg = 'expected true'): void
    {
        if (!$cond) {
            throw new \AssertionError($msg);
        }
    }

    /**
     * @template E of \Throwable
     * @param class-string<E> $class
     * @return E
     */
    public static function throws(string $class, callable $fn, ?string $pattern = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new \AssertionError("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($pattern !== null && !preg_match($pattern, $e->getMessage())) {
                throw new \AssertionError("message '{$e->getMessage()}' does not match $pattern");
            }
            return $e;
        }
        throw new \AssertionError("expected $class, nothing thrown");
    }
}

// ---------------------------------------------------------------- fake server

final class FakeServer
{
    public string $baseUrl;
    private string $dir;
    /** @var resource */
    private $proc;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/moolah-php-fake-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $env = array_merge(getenv(), ['FAKE_STATE_DIR' => $this->dir, 'PHP_CLI_SERVER_WORKERS' => '4']);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_server_router.php'];
        $this->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']], $pipes, null, $env);
        $this->baseUrl = "http://127.0.0.1:$port";
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('fake server did not start');
    }

    /** @param array<string, mixed> ...$responses */
    public function respond(array ...$responses): void
    {
        @unlink($this->dir . '/requests.jsonl');
        file_put_contents($this->dir . '/script.json', json_encode($responses));
    }

    /** @return list<array{method: string, path: string, query: array<string, string>, headers: array<string, string>, raw: string, body: mixed}> */
    public function requests(): array
    {
        $file = $this->dir . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            $parts = parse_url($r['uri']);
            parse_str($parts['query'] ?? '', $q);
            $out[] = [
                'method' => $r['method'],
                'path' => $parts['path'],
                'query' => $q,
                'headers' => $r['headers'],
                'raw' => $r['raw'],
                'body' => $r['raw'] === '' ? null : json_decode($r['raw'], true),
            ];
        }
        return $out;
    }

    public function close(): void
    {
        proc_terminate($this->proc);
        proc_close($this->proc);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }
}

/** @param array<string, string> $headers */
function json(int $status, mixed $body, array $headers = []): array
{
    return ['status' => $status, 'body' => $body === null ? '' : json_encode($body), 'headers' => $headers];
}

/** In-process transport for failure modes php -S cannot produce (dropped connections). */
final class ScriptedTransport implements Transport
{
    /** @var list<array{string, string, ?string}> */
    public array $calls = [];

    /** @param list<RawResponse|\Throwable> $steps */
    public function __construct(private array $steps)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse
    {
        $this->calls[] = [$method, $url, $body];
        $step = count($this->steps) > 1 ? array_shift($this->steps) : $this->steps[0];
        if ($step instanceof \Throwable) {
            throw $step;
        }
        return $step;
    }
}

function scripted(array $steps, ?ScriptedTransport &$t = null): MoolahClient
{
    $t = new ScriptedTransport($steps);
    $c = new MoolahClient('k', baseUrl: 'https://example.test', transport: $t);
    $c->core->setSleep(static function (float $s): void {
    });
    return $c;
}

// ---------------------------------------------------------------- tests

$TXN = ['id' => 'txn_1', 'status' => 'CAPTURED', 'amounts' => ['orderAmount' => 2500, 'currency' => 'USD']];
$FAST = ['Retry-After' => '0'];

$srv = new FakeServer();
$client = new MoolahClient('test_key_123', baseUrl: $srv->baseUrl . '/', userAgent: 'unit-test/1.0');

echo "request shape\n";

T::test('bearer auth, user agent, JSON; typed response', function () use ($srv, $client, $TXN) {
    $srv->respond(json(202, $TXN));
    $res = $client->tokens->charge(amount: 2500, paymentToken: 'tok_1', fundingSourceType: FundingSourceType::Card);
    T::true($res instanceof Transaction);
    T::eq('txn_1', $res->id);
    T::eq(2500, $res->amounts?->orderAmount);
    T::eq($TXN, $res->toArray());
    $r = $srv->requests()[0];
    T::eq('POST', $r['method']);
    T::eq('/api/v2/token/charge', $r['path']);
    T::eq('Bearer test_key_123', $r['headers']['authorization']);
    T::eq('application/json', $r['headers']['content-type']);
    T::eq('application/json', $r['headers']['accept']);
    T::true((bool) preg_match('#^moolah-poynt-php/' . preg_quote(Version::VERSION, '#') . ' php/\S+ unit-test/1\.0$#', $r['headers']['user-agent']), $r['headers']['user-agent']);
    T::eq(['amount' => 2500, 'currency' => 'USD', 'paymentToken' => 'tok_1', 'fundingSourceType' => 'CARD'], $r['body']);
});

T::test('intent and explicit mit=false; unset fields omitted', function () use ($srv, $client, $TXN) {
    $srv->respond(json(202, $TXN));
    $client->tokens->charge(
        amount: 100,
        paymentToken: 'tok_1',
        fundingSourceType: 'CARD',
        intent: CardOnFileIntent::Recurring,
        mit: false,
        transactionNumber: 'inv-42',
        surcharge: ['surchargeAmount' => 3, 'feeType' => 'SURCHARGE', 'signature' => 'sig'],
    );
    T::eq('{"amount":100,"currency":"USD","paymentToken":"tok_1","fundingSourceType":"CARD","transactionNumber":"inv-42","surcharge":{"surchargeAmount":3,"feeType":"SURCHARGE","signature":"sig"},"intent":"RECURRING_COF_TXN","mit":false}', $srv->requests()[0]['raw']);
});

T::test('authorize and token refund hit v2', function () use ($srv, $client, $TXN) {
    $srv->respond(json(202, $TXN));
    $client->tokens->authorize(amount: 5, paymentToken: 't', fundingSourceType: FundingSourceType::Ach, intent: 'INSTALLMENT_COF_TXN', mit: true);
    $client->tokens->refund(parentId: 'txn_1', amount: 5, paymentToken: 't', fundingSourceType: FundingSourceType::Card);
    [$a, $r] = $srv->requests();
    T::eq('/api/v2/token/authorize', $a['path']);
    T::eq('CHEQUE', $a['body']['fundingSourceType']);
    T::eq('INSTALLMENT_COF_TXN', $a['body']['intent']);
    T::eq(true, $a['body']['mit']);
    T::eq('/api/v2/token/refund', $r['path']);
    T::eq('txn_1', $r['body']['parentId']);
    T::true(!array_key_exists('mit', $r['body']));
});

T::test('tokenize maps cardAgreementId and ACH fields', function () use ($srv, $client) {
    $srv->respond(json(202, ['paymentToken' => 'tok_9', 'avsResponse' => ['postalCodeResult' => 'MATCH']]));
    $res = $client->tokens->tokenize(cardAgreementId: 'agr_1', cardOnFile: true);
    T::eq('tok_9', $res->paymentToken);
    T::eq('MATCH', $res->avsResponse?->postalCodeResult);
    $client->tokens->tokenize(nonce: 'nonce_1', financialInstrumentAgreement: ['version' => 2, 'agreedOn' => '2026-10-28'], saveFinancialInstrumentOnFile: true);
    [$a, $b] = $srv->requests();
    T::eq('/api/token/tokenize', $a['path']);
    T::eq('{"card":{"cardAgreement":{"id":"agr_1"}},"cardOnFile":true}', $a['raw']);
    T::eq('{"nonce":"nonce_1","financialInstrumentAgreement":{"version":2,"agreedOn":"2026-10-28"},"saveFinancialInstrumentOnFile":true}', $b['raw']);
});

T::test('transactions list/get/capture/refund', function () use ($srv, $client) {
    $srv->respond(json(202, ['count' => 1, 'transactions' => [['id' => 't1']]]));
    $list = $client->transactions->list(startAt: new \DateTimeImmutable('2026-10-01T00:00:00Z'), endAt: '2026-10-02T00:00:00Z', limit: 10, orderBy: 'desc');
    T::eq('t1', $list->transactions[0]->id);
    $client->transactions->get('txn/1');
    $client->transactions->capture('txn_1');
    $client->transactions->capture('txn_1', amount: 700);
    $client->transactions->refund('txn_1', amount: 300, notes: 'partial');
    [$l, $g, $c, $c2, $r] = $srv->requests();
    T::eq(['GET', '/api/transactions'], [$l['method'], $l['path']]);
    T::eq(['startAt' => '2026-10-01T00:00:00+00:00', 'endAt' => '2026-10-02T00:00:00Z', 'limit' => '10', 'orderBy' => 'desc'], $l['query']);
    T::eq('', $l['raw']);
    T::true(!isset($l['headers']['content-type']));
    T::eq('/api/transactions/txn%2F1', $g['path']);
    T::eq('/api/transactions/txn_1/capture', $c['path']);
    T::eq('{}', $c['raw']);
    T::eq('{"amount":700}', $c2['raw']);
    T::eq('/api/v2/transactions/txn_1/refund', $r['path']);
    T::eq(['amount' => 300, 'notes' => 'partial'], $r['body']);
});

T::test('terminal payment, reconcile, devices, ACH status', function () use ($srv, $client) {
    $srv->respond(json(200, ['status' => 'resolved', 'poynt' => ['referenceId' => 'x', 'status' => 'COMPLETED']]));
    $res = $client->payments->terminal('dev 1', amount: 1234);
    T::eq('COMPLETED', $res->poynt?->status);
    $client->payments->terminal('dev1', amount: 1, referenceId: 'my-ref', skipReceiptScreen: true);
    [$t1, $t2] = $srv->requests();
    $srv->respond(json(200, ['outcome' => 'COMPLETED', 'matchedBy' => 'REFERENCE', 'requestedAmount' => 1, 'transactions' => [['id' => 'tx', 'amount' => 1]]]));
    $rec = $client->payments->reconcile('my-ref');
    $r = $srv->requests()[0];
    $srv->respond(json(200, [['deviceID' => 'dev1', 'name' => 'Front desk', '_id' => 'm1']]));
    $devices = $client->devices->list();
    $d = $srv->requests()[0];
    $srv->respond(json(200, ['allowAch' => true, 'businessId' => 'biz']));
    $ach = $client->business->achStatus();
    $a = $srv->requests()[0];

    T::eq('/api/payments/dev%201', $t1['path']);
    T::true((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $t1['body']['referenceId']));
    T::eq(['amount', 'currency', 'referenceId'], array_keys($t1['body']));
    T::eq(['amount' => 1, 'currency' => 'USD', 'referenceId' => 'my-ref', 'skipReceiptScreen' => true], $t2['body']);
    T::eq('/api/payments/reconcile', $r['path']);
    T::eq(['referenceId' => 'my-ref'], $r['query']);
    T::eq('COMPLETED', $rec->outcome);
    T::eq('tx', $rec->transactions[0]->id);
    T::eq('/api/devices', $d['path']);
    T::true($devices[0] instanceof Device);
    T::eq('Front desk', $devices[0]->name);
    T::eq('m1', $devices[0]->id);
    T::eq('/api/business/ach-status', $a['path']);
    T::eq(true, $ach->allowAch);
});

T::test('argument validation', function () use ($srv, $client) {
    $srv->respond(json(500, []));
    T::throws(\InvalidArgumentException::class, fn () => new MoolahClient(''));
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->charge(amount: -1, paymentToken: 't', fundingSourceType: 'CARD'), '/non-negative/');
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->charge(amount: 100, paymentToken: 't'), '/fundingSourceType is required/');
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->charge(amount: 100, paymentToken: 't', cardToken: 'c', fundingSourceType: 'CARD'), '/not both/');
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->tokenize(nonce: 'n', cardAgreementId: 'a'), '/exactly one/');
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->tokenize(), '/exactly one/');
    T::throws(\InvalidArgumentException::class, fn () => $client->tokens->refund(parentId: '', amount: 1, paymentToken: 't', fundingSourceType: 'CARD'), '/parentId/');
    T::throws(\TypeError::class, fn () => $client->tokens->charge(amount: 12.5, paymentToken: 't', fundingSourceType: 'CARD'));
    T::eq([], $srv->requests());
});

echo "error mapping\n";

T::test('HTTPErr with numeric code', function () use ($srv, $client) {
    $body = ['code' => 104, 'message' => 'this transaction has already been refunded', 'error' => null];
    $srv->respond(json(400, $body, ['X-Request-Id' => 'req_abc']));
    $e = T::throws(MoolahApiException::class, fn () => $client->transactions->refund('txn_1'));
    T::eq([400, '104', 'this transaction has already been refunded', 'req_abc'], [$e->status, $e->apiCode, $e->getMessage(), $e->requestId]);
    T::eq($body, $e->body);
});

T::test('{error: string}, poynt request id, zero code ignored', function () use ($srv, $client) {
    $srv->respond(json(400, ['error' => 'provide exactly one'], ['X-Poynt-Request-Id' => 'poynt_1']));
    $e = T::throws(MoolahApiException::class, fn () => $client->tokens->tokenize(nonce: 'n'));
    T::eq(['provide exactly one', null, 'poynt_1'], [$e->getMessage(), $e->apiCode, $e->requestId]);
    $srv->respond(json(500, ['code' => 0, 'message' => 'boom']));
    $e = T::throws(MoolahApiException::class, fn () => $client->devices->list(maxRetries: 0));
    T::eq([500, null, 'boom'], [$e->status, $e->apiCode, $e->getMessage()]);
});

T::test('non-JSON error body', function () use ($srv, $client) {
    $srv->respond(['status' => 401, 'body' => '<html>nope</html>', 'contentType' => 'text/html']);
    $e = T::throws(MoolahApiException::class, fn () => $client->devices->list());
    T::eq([401, 'HTTP 401', '<html>nope</html>'], [$e->status, $e->getMessage(), $e->body]);
});

T::test('redirects are not followed', function () use ($srv, $client) {
    $srv->respond(json(302, [], ['Location' => '/elsewhere']));
    $e = T::throws(MoolahApiException::class, fn () => $client->transactions->capture('t1'));
    T::eq(302, $e->status);
    T::eq(1, count($srv->requests()));
});

T::test('504 TERMINAL_NO_RESPONSE, never retried', function () use ($srv, $client) {
    $srv->respond(json(504, ['code' => 'TERMINAL_NO_RESPONSE', 'message' => 'no result', 'referenceId' => 'ref_77']));
    $e = T::throws(TerminalNoResponseException::class, fn () => $client->payments->terminal('dev1', amount: 100, referenceId: 'ref_77'));
    T::true($e instanceof MoolahApiException);
    T::eq([504, 'TERMINAL_NO_RESPONSE', 'ref_77'], [$e->status, $e->apiCode, $e->referenceId]);
    T::eq(1, count($srv->requests()));
});

T::test('gateway HTML on a terminal payment', function () use ($srv, $client) {
    $srv->respond(['status' => 502, 'body' => '<html>Bad gateway</html>', 'contentType' => 'text/html']);
    $e = T::throws(TerminalNoResponseException::class, fn () => $client->payments->terminal('dev1', amount: 100));
    T::eq(502, $e->status);
    T::eq($srv->requests()[0]['body']['referenceId'], $e->referenceId);
});

T::test('400 on a terminal payment is a plain API error', function () use ($srv, $client) {
    $srv->respond(json(400, ['message' => 'bad amount']));
    $e = T::throws(MoolahApiException::class, fn () => $client->payments->terminal('dev1', amount: 0));
    T::true(!$e instanceof TerminalNoResponseException);
});

T::test('connection refused -> MoolahConnectionException; on terminal -> TerminalNoResponseException', function () {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $addr = (string) stream_socket_get_name($sock, false);
    fclose($sock);
    $c = new MoolahClient('k', baseUrl: "http://$addr", maxRetries: 0);
    $e = T::throws(MoolahConnectionException::class, fn () => $c->devices->list());
    T::eq(false, $e->timedOut);
    $e = T::throws(TerminalNoResponseException::class, fn () => $c->payments->terminal('d', amount: 1, referenceId: 'r1'));
    T::eq([0, 'r1'], [$e->status, $e->referenceId]);
});

T::test('timeout -> MoolahConnectionException(timedOut)', function () use ($srv, $client) {
    $srv->respond(['status' => 200, 'body' => '[]', 'sleep' => 1.5]);
    $e = T::throws(MoolahConnectionException::class, fn () => $client->devices->list(timeout: 0.3, maxRetries: 0));
    T::eq(true, $e->timedOut);
});

echo "retries\n";

T::test('GET retried on 503 then succeeds (real server)', function () use ($srv, $client) {
    $srv->respond(json(503, ['message' => 'busy'], ['Retry-After' => '0']), json(200, []));
    T::eq([], $client->devices->list());
    T::eq(2, count($srv->requests()));
});

T::test('GET gives up after maxRetries; per-call maxRetries', function () use ($srv, $client, $FAST) {
    $srv->respond(json(502, ['message' => 'bad gateway'], $FAST));
    $e = T::throws(MoolahApiException::class, fn () => $client->devices->list());
    T::eq(502, $e->status);
    T::eq(3, count($srv->requests()));
    $srv->respond(json(429, [], $FAST));
    T::throws(MoolahApiException::class, fn () => $client->devices->list(maxRetries: 0));
    T::eq(1, count($srv->requests()));
});

T::test('500 not retried', function () use ($srv, $client, $FAST) {
    $srv->respond(json(500, [], $FAST));
    T::throws(MoolahApiException::class, fn () => $client->devices->list());
    T::eq(1, count($srv->requests()));
});

T::test('POST without transactionNumber not retried', function () use ($srv, $client, $FAST, $TXN) {
    $srv->respond(json(503, [], $FAST), json(202, $TXN));
    T::throws(MoolahApiException::class, fn () => $client->tokens->charge(amount: 1, paymentToken: 't', fundingSourceType: 'CARD'));
    T::eq(1, count($srv->requests()));
});

T::test('POST with transactionNumber retried with the same body', function () use ($srv, $client, $FAST, $TXN) {
    $srv->respond(json(503, [], $FAST), json(429, [], $FAST), json(202, $TXN));
    $res = $client->tokens->charge(amount: 1, paymentToken: 't', fundingSourceType: 'CARD', transactionNumber: 'inv-1');
    T::eq('txn_1', $res->id);
    $reqs = $srv->requests();
    T::eq(3, count($reqs));
    T::eq($reqs[0]['raw'], $reqs[2]['raw']);
});

T::test('network error on GET is retried', function () {
    $c = scripted([new MoolahConnectionException('reset'), new RawResponse(200, [], '{"allowAch":false}')], $t);
    T::eq(false, $c->business->achStatus()->allowAch);
    T::eq(2, count($t->calls));
});

T::test('network error on POST without transactionNumber: one attempt', function () {
    $c = scripted([new MoolahConnectionException('reset')], $t);
    T::throws(MoolahConnectionException::class, fn () => $c->transactions->capture('t1'));
    T::eq(1, count($t->calls));
});

T::test('dropped connection on a terminal payment', function () {
    $c = scripted([new MoolahConnectionException('reset')], $t);
    $e = T::throws(TerminalNoResponseException::class, fn () => $c->payments->terminal('d', amount: 1, referenceId: 'r9', transactionNumber: 'n'));
    T::eq([0, 'r9'], [$e->status, $e->referenceId]);
    T::eq(1, count($t->calls));
});

T::test('terminal payment never retried on 503', function () {
    $c = scripted([new RawResponse(503, [], '{}')], $t);
    T::throws(TerminalNoResponseException::class, fn () => $c->payments->terminal('d', amount: 1, transactionNumber: 'n'));
    T::eq(1, count($t->calls));
});

echo "webhooks\n";

// Vectors signed with Python's stdlib hmac (testdata/webhooks/generate.py), so this
// suite checks the PHP implementation against a different one.
$vectors = json_decode((string) file_get_contents(__DIR__ . '/../../testdata/webhooks/vectors-python.json'), true);
$hdrs = static fn (array $c): array => ['webhook-id' => $c['id'], 'webhook-timestamp' => (string) $c['timestamp'], 'webhook-signature' => $c['signature']];
$wh = new Webhooks();

T::test('Standard Webhooks reference vector', function () use ($wh) {
    $ev = $wh->verify('{"test": 2432232314}', [
        'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
        'webhook-timestamp' => '1614265330',
        'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
    ], 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', now: 1614265330);
    T::eq(2432232314, $ev->raw['test']);
});

foreach ($vectors['cases'] as $c) {
    T::test('vector: ' . $c['name'], function () use ($wh, $c, $hdrs) {
        if ($c['valid']) {
            T::eq(json_decode($c['body'], true)['id'], $wh->verify($c['body'], $hdrs($c), $c['secret'], now: $c['now'])->id);
        } else {
            T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], $hdrs($c), $c['secret'], now: $c['now']));
        }
    });
}

T::test('event model and portal type', function () use ($vectors, $hdrs, $client) {
    $c = $vectors['cases'][0];
    $ev = $client->webhooks->verify($c['body'], $hdrs($c), $c['secret'], now: $c['now']);
    T::eq('TRANSACTION_CAPTURED', $ev->eventType);
    T::eq(WebhookEventType::TRANSACTION_CAPTURED, $ev->type);
    T::eq('c2f1a0c4-3b4f-4a8e-9d1c-0b6a7e5f2d11', $ev->businessId);
    T::eq('/transactions', $ev->resource);
    T::eq('5f0e7c1a-1111-2222-3333-444455556666', $ev->resourceId);
    T::eq('2026-10-28T17:00:00Z', $ev->createdAt);
    T::eq('2026-10-28T17:00:01Z', $ev->updatedAt);
    T::eq('resource', $ev->links[0]->rel);
    T::eq(6, count(WebhookEventType::ALL));
});

T::test('header forms: mixed case, $_SERVER keys, arrays', function () use ($vectors, $wh) {
    $c = $vectors['cases'][0];
    T::eq('transaction.captured', $wh->verify($c['body'], ['Webhook-Id' => $c['id'], 'WEBHOOK-TIMESTAMP' => (string) $c['timestamp'], 'webhook-signature' => ['v1,AAAA', $c['signature']]], $c['secret'], now: $c['now'])->type);
    T::eq('transaction.captured', $wh->verify($c['body'], ['HTTP_WEBHOOK_ID' => $c['id'], 'HTTP_WEBHOOK_TIMESTAMP' => (string) $c['timestamp'], 'HTTP_WEBHOOK_SIGNATURE' => $c['signature']], $c['secret'], now: $c['now'])->type);
});

T::test('failures: missing header, bad timestamp, bad secret, clock, tolerance', function () use ($vectors, $wh, $hdrs) {
    $c = $vectors['cases'][0];
    T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], ['webhook-id' => $c['id'], 'webhook-timestamp' => (string) $c['timestamp']], $c['secret'], now: $c['now']), '/Missing/');
    T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], ['webhook-timestamp' => '12x'] + $hdrs($c), $c['secret'], now: $c['now']), '/timestamp/');
    T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], $hdrs($c), 'whsec_!!notbase64', now: $c['now']), '/base64/');
    T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], $hdrs($c), $c['secret']), '/too old/');
    T::throws(WebhookVerificationException::class, fn () => $wh->verify($c['body'], $hdrs($c), $c['secret'], 60, $c['now'] + 61), '/too old/');
});

$srv->close();

$failed = count(T::$failures);
echo "\n" . T::$pass . " passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
