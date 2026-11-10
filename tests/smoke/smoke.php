<?php

// The library over real HTTP, with no test framework: a token, a read, a write
// retried after a 503 with the same Idempotency-Key, two pages, an error, a
// timeout and a refused connection.
//   php -S 127.0.0.1:8099 tests/smoke/server.php & php tests/smoke/smoke.php http://127.0.0.1:8099
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Reeds\Exception\ConnectionException;
use Reeds\Exception\ConnectionTimeoutException;
use Reeds\Exception\NotFoundException;
use Reeds\Reeds;

$check = static function (bool $ok, string $what): void {
    if (!$ok) {
        fwrite(STDERR, "smoke FAILED: {$what}\n");
        exit(1);
    }
};

$log = sys_get_temp_dir() . '/reeds-smoke.log';
@unlink($log);
@unlink(sys_get_temp_dir() . '/reeds-smoke-busy');

$reeds = new Reeds(['client_id' => 'rci_test_smoke', 'client_secret' => 'rcs_test_smoke', 'base_url' => $argv[1] ?? 'http://127.0.0.1:8099']);
$check($reeds->mode === 'test', 'mode');
$check(($reeds->shipping->coverage->get()['states'][0]['name'] ?? null) === 'Lagos', 'coverage');

$made = $reeds->shipping->deliveries->create(['quote_id' => 'qt_1', 'ready' => true]);
$check($made['id'] === 'dlv_1' && $made['quote_id'] === 'qt_1', 'create');

$ids = [];
foreach ($reeds->shipping->deliveries->list(['limit' => 2]) as $delivery) {
    $ids[] = $delivery['id'];
}
$check($ids === ['dlv_3', 'dlv_2', 'dlv_1'], 'pagination');

try {
    $reeds->shipping->deliveries->get('dlv_x');
    $check(false, 'a 404 throws');
} catch (NotFoundException $error) {
    $check($error->errorCode === 'NOT_FOUND' && $error->requestId === 'req_smoke', 'the 404\'s code and request id');
}

// The two failures with no response, as the real transport reports them.
try {
    $reeds->shipping->wallet->get(['timeout' => 0.3, 'max_retries' => 0]);
    $check(false, 'a slow answer times out');
} catch (ConnectionTimeoutException $error) {
    $check($error->errorCode === 'TIMEOUT', 'the timeout\'s code');
}
$nobody = new Reeds(['client_id' => 'rci_test_smoke', 'client_secret' => 'rcs_test_smoke', 'base_url' => 'http://127.0.0.1:9', 'max_retries' => 0]);
try {
    $nobody->shipping->coverage->get();
    $check(false, 'no server throws');
} catch (ConnectionException $error) {
    $check(!$error instanceof ConnectionTimeoutException && $error->errorCode === 'CONNECTION_FAILED', 'a refused connection');
}

$calls = array_map(static fn (string $line) => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($log))));
$posts = array_values(array_filter($calls, static fn ($c) => $c['method'] === 'POST' && $c['path'] === '/shipping/v1/deliveries'));
$check(count($posts) === 2, 'the 503 was retried');
$check($posts[0]['key'] !== null && $posts[0]['key'] === $posts[1]['key'], 'the retry reused the Idempotency-Key');
$check(count(array_filter($calls, static fn ($c) => $c['path'] === '/oauth/token')) === 1, 'one token for every call');

echo 'smoke ok on PHP ' . PHP_VERSION . "\n";
