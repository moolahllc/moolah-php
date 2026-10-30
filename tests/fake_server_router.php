<?php

// Router for `php -S`: a scripted fake of the Moolah API used by tests/run.php.
// State lives in files under $FAKE_STATE_DIR because php -S keeps nothing between requests:
//   script.json   - queued responses; the last one stays in place
//   requests.jsonl - one recorded request per line

declare(strict_types=1);

$dir = getenv('FAKE_STATE_DIR') ?: sys_get_temp_dir();
$scriptFile = $dir . '/script.json';
$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
$raw = file_get_contents('php://input');
file_put_contents($dir . '/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'raw' => $raw,
]) . "\n", FILE_APPEND);

$script = is_file($scriptFile) ? json_decode((string) file_get_contents($scriptFile), true) : [];
$next = $script[0] ?? ['status' => 500, 'body' => '{"message":"no handler queued"}'];
if (count($script) > 1) {
    array_shift($script);
    file_put_contents($scriptFile, json_encode($script));
}
flock($lock, LOCK_UN);

if (($next['sleep'] ?? 0) > 0) {
    usleep((int) ($next['sleep'] * 1_000_000));
}
http_response_code($next['status']);
header('Content-Type: ' . ($next['contentType'] ?? 'application/json'));
foreach ($next['headers'] ?? [] as $k => $v) {
    header("$k: $v");
}
echo $next['body'] ?? '';
return true;
