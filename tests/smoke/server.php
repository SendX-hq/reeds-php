<?php

// The Reeds API played by PHP's built-in server, for tests/smoke/smoke.php:
//   php -S 127.0.0.1:8099 tests/smoke/server.php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = sys_get_temp_dir() . '/reeds-smoke.log';
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
]) . "\n", FILE_APPEND);

$send = static function (int $status, array $body, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Request-Id: req_smoke');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($body);
};

if ($path === '/oauth/token') {
    $send(200, ['access_token' => 'tok_smoke', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'shipping', 'livemode' => false]);
} elseif ($path === '/shipping/v1/coverage') {
    $send(200, ['status' => 'success', 'data' => ['states' => [['name' => 'Lagos']]]]);
} elseif ($path === '/shipping/v1/deliveries' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $busy = sys_get_temp_dir() . '/reeds-smoke-busy';
    if (!file_exists($busy)) {
        touch($busy);
        $send(503, ['status' => 'failed', 'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Busy.', 'doc_url' => 'https://developer.getreeds.co/errors/SERVICE_UNAVAILABLE']], ['Retry-After' => '0']);
    } else {
        $send(201, ['status' => 'success', 'data' => ['id' => 'dlv_1'] + json_decode((string) file_get_contents('php://input'), true)]);
    }
} elseif ($path === '/shipping/v1/deliveries') {
    $after = $_GET['starting_after'] ?? null;
    $send(200, ['status' => 'success', 'data' => $after ? [['id' => 'dlv_1']] : [['id' => 'dlv_3'], ['id' => 'dlv_2']], 'has_more' => !$after]);
} else {
    $send(404, ['status' => 'failed', 'error' => ['code' => 'NOT_FOUND', 'message' => 'No such delivery.', 'doc_url' => 'https://developer.getreeds.co/errors/NOT_FOUND']]);
}
