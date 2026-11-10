<?php

declare(strict_types=1);

namespace Reeds\Core;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Reeds\Exception\ApiException;
use Reeds\Exception\AuthenticationException;
use Reeds\Exception\ConnectionException;
use Reeds\Exception\ConnectionTimeoutException;
use Reeds\Version;

/**
 * The part of the library every API module shares: credentials and the token,
 * the HTTP calls, retries, idempotency keys, errors, pagination and files. It
 * knows nothing about deliveries or orders; the modules give it paths.
 *
 * @internal Use Reeds\Reeds.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.getreeds.co';
    private const DEFAULT_TIMEOUT = 60.0;
    private const DEFAULT_MAX_RETRIES = 2;
    /** A token is refreshed this many seconds before it expires. */
    private const REFRESH_MARGIN = 60;
    /** A Retry-After longer than this isn't waited for: the exception goes to the caller, with `retryAfter` on it. */
    private const MAX_RETRY_AFTER_SECONDS = 30;

    public readonly string $baseUrl;
    /** "test" or "live", from the client id; null when there is none (webhook verification only). */
    public readonly ?string $mode;

    private readonly ?string $clientId;
    private readonly ?string $clientSecret;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly ClientInterface $http;
    /** @var array<string, string> */
    private readonly array $defaultHeaders;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;
    /** @var \Closure(): float */
    private readonly \Closure $random;
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var array{value: string, expires_at: float, scopes: list<string>, livemode: bool}|null */
    private ?array $token = null;

    /**
     * @param array{
     *     client_id?: string|null,
     *     client_secret?: string|null,
     *     base_url?: string|null,
     *     timeout?: float|int,
     *     max_retries?: int,
     *     http_client?: ClientInterface,
     *     default_headers?: array<string, string>,
     *     _sleep?: callable(float): void,
     *     _random?: callable(): float,
     *     _clock?: callable(): float,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->clientId = self::stringOrNull($options['client_id'] ?? null) ?? self::env('REEDS_CLIENT_ID');
        $this->clientSecret = self::stringOrNull($options['client_secret'] ?? null) ?? self::env('REEDS_CLIENT_SECRET');
        // An empty string (an unset environment variable passed through) means the default too.
        $baseUrl = self::stringOrNull($options['base_url'] ?? null) ?? self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->mode = match (true) {
            $this->clientId !== null && str_starts_with($this->clientId, 'rci_live_') => 'live',
            $this->clientId !== null && str_starts_with($this->clientId, 'rci_test_') => 'test',
            default => null,
        };
        $this->timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $this->http = $options['http_client'] ?? new Guzzle();
        $this->defaultHeaders = $options['default_headers'] ?? [];
        $this->sleep = \Closure::fromCallable($options['_sleep'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        });
        $this->random = \Closure::fromCallable($options['_random'] ?? static fn (): float => mt_rand() / mt_getrandmax());
        $this->clock = \Closure::fromCallable($options['_clock'] ?? static fn (): float => microtime(true));
    }

    // ------------------------------------------------------------ the token

    /**
     * The scopes the access token carries: one per Reeds API the account may use.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->token()['scopes'];
    }

    /** @return array{value: string, expires_at: float, scopes: list<string>, livemode: bool} */
    private function token(): array
    {
        if ($this->token !== null && $this->token['expires_at'] - self::REFRESH_MARGIN > ($this->clock)()) {
            return $this->token;
        }
        if ($this->clientId === null || $this->clientSecret === null) {
            throw new AuthenticationException(
                'MISSING_CREDENTIALS',
                'Pass client_id and client_secret, or set REEDS_CLIENT_ID and REEDS_CLIENT_SECRET. Find them in Dashboard → Developers.',
            );
        }
        $response = $this->send('POST', '/oauth/token', [
            'auth' => false,
            'form' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
        ]);
        $body = self::decode((string) $response->getBody());
        if (!is_array($body) || !is_string($body['access_token'] ?? null)) {
            throw new ApiException('UNEXPECTED_RESPONSE', 'The token endpoint answered without an access token.', $response->getStatusCode(), [], null, self::requestId($response));
        }
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';
        $this->token = [
            'value' => $body['access_token'],
            'expires_at' => ($this->clock)() + (is_numeric($body['expires_in'] ?? null) ? (float) $body['expires_in'] : 3600.0),
            'scopes' => array_values(array_filter(preg_split('/\s+/', $scope) ?: [], static fn (string $s): bool => $s !== '')),
            'livemode' => (bool) ($body['livemode'] ?? false),
        ];
        return $this->token;
    }

    // ------------------------------------------------------------ calls the modules make

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     */
    public function get(string $path, array $query = [], array $options = []): mixed
    {
        return $this->data('GET', $path, ['query' => $query, 'options' => $options]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $options
     */
    public function post(string $path, ?array $json = null, array $options = []): mixed
    {
        return $this->data('POST', $path, ['json' => $json, 'options' => $options]);
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options
     */
    public function patch(string $path, array $json, array $options = []): mixed
    {
        return $this->data('PATCH', $path, ['json' => $json, 'options' => $options]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     * @return Page<array<array-key, mixed>>
     */
    public function list(string $path, array $query = [], array $options = []): Page
    {
        $response = $this->send('GET', $path, ['query' => $query, 'options' => $options]);
        $body = self::decode((string) $response->getBody());
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            throw $this->unexpected($response);
        }
        /** @var list<array<array-key, mixed>> $data */
        $data = array_values($body['data']);
        return new Page($data, (bool) ($body['has_more'] ?? false), fn (string $after): Page => $this->list($path, [...$query, 'starting_after' => $after], $options));
    }

    /** @param array<string, mixed> $options */
    public function file(string $path, array $options = []): FileResponse
    {
        $response = $this->send('GET', $path, ['accept' => 'application/pdf', 'options' => $options]);
        return new FileResponse(
            self::filenameOf($response->getHeaderLine('Content-Disposition')),
            $response->getHeaderLine('Content-Type') !== '' ? $response->getHeaderLine('Content-Type') : 'application/octet-stream',
            (string) $response->getBody(),
        );
    }

    /** @param array{query?: array<string, mixed>, json?: array<string, mixed>|null, options?: array<string, mixed>} $init */
    private function data(string $method, string $path, array $init): mixed
    {
        $response = $this->send($method, $path, $init);
        $body = self::decode((string) $response->getBody());
        if (!is_array($body) || ($body['status'] ?? null) !== 'success' || !array_key_exists('data', $body)) {
            throw $this->unexpected($response);
        }
        return $body['data'];
    }

    private function unexpected(ResponseInterface $response): ApiException
    {
        return new ApiException(
            'UNEXPECTED_RESPONSE',
            "Reeds answered {$response->getStatusCode()} with a body this version of the library doesn't recognise.",
            $response->getStatusCode(),
            [],
            null,
            self::requestId($response),
        );
    }

    // ------------------------------------------------------------ one request, with retries

    /** Retried: no response, 429, 502, 503, 504, a 500 on a GET, and 409 REQUEST_IN_PROGRESS (the same key still running). */
    private function retryable(string $method, int $status, ?string $code): bool
    {
        if (in_array($status, [429, 502, 503, 504], true)) {
            return true;
        }
        if ($status === 500) {
            return $method === 'GET';
        }
        return $status === 409 && $code === 'REQUEST_IN_PROGRESS';
    }

    /** About 0.5 s, then 1 s, 2 s ..., at most 8 s, with jitter. In seconds. */
    private function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * (2 ** $attempt)) * (1 - 0.25 * ($this->random)());
    }

    /**
     * @param array{
     *     query?: array<string, mixed>,
     *     json?: array<string, mixed>|null,
     *     form?: array<string, string>,
     *     accept?: string,
     *     auth?: bool,
     *     options?: array<string, mixed>,
     * } $init
     */
    private function send(string $method, string $path, array $init): ResponseInterface
    {
        $options = $init['options'] ?? [];
        $auth = $init['auth'] ?? true;
        $url = $this->baseUrl . $path . self::queryString($init['query'] ?? []);
        // Every POST to an API carries a key, so a retry can never create or charge twice.
        $idempotencyKey = null;
        if ($auth) {
            $idempotencyKey = is_string($options['idempotency_key'] ?? null) ? $options['idempotency_key'] : ($method === 'POST' ? self::uuid4() : null);
        }
        $maxRetries = isset($options['max_retries']) ? (int) $options['max_retries'] : $this->maxRetries;
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout;
        $refreshed = false;

        for ($attempt = 0; ; $attempt++) {
            $token = $auth ? $this->token() : null;
            $headers = array_merge(['Accept' => $init['accept'] ?? 'application/json'], $this->defaultHeaders);
            $headers['User-Agent'] = 'reeds-php/' . Version::VERSION . ' php/' . PHP_VERSION;
            if ($token !== null) {
                $headers['Authorization'] = 'Bearer ' . $token['value'];
            }
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            $request = ['http_errors' => false, 'timeout' => $timeout, 'allow_redirects' => false];
            if (isset($init['form'])) {
                $request['form_params'] = $init['form'];
            } elseif (array_key_exists('json', $init) && $init['json'] !== null) {
                $headers['Content-Type'] = 'application/json';
                $request['body'] = json_encode($init['json'] === [] ? new \stdClass() : $init['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
            if (isset($options['headers']) && is_array($options['headers'])) {
                $headers = array_merge($headers, $options['headers']);
            }
            // Guzzle 8 refuses header values that aren't strings.
            $request['headers'] = array_map(static fn ($value) => is_array($value) ? array_map('strval', $value) : (string) $value, $headers);

            try {
                $response = $this->http->request($method, $url, $request);
            } catch (TransferException $error) {
                if ($attempt < $maxRetries) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                if (self::isTimeout($error)) {
                    throw new ConnectionTimeoutException('TIMEOUT', sprintf('No answer from Reeds within %s s.', $timeout + 0), null, [], null, null, null, $error);
                }
                throw new ConnectionException('CONNECTION_FAILED', "Couldn't reach Reeds: " . $error->getMessage(), null, [], null, null, null, $error);
            }
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $response;
            }

            $body = self::decode((string) $response->getBody());
            $code = is_array($body) && is_array($body['error'] ?? null) && is_string($body['error']['code'] ?? null) ? $body['error']['code'] : null;
            // The token expired or was revoked: one fresh token, one more try (not counted as a retry).
            if ($token !== null && $status === 401 && !$refreshed && ($code === 'TOKEN_EXPIRED' || $code === 'UNAUTHENTICATED')) {
                if ($this->token !== null && $this->token['value'] === $token['value']) {
                    $this->token = null;
                }
                $refreshed = true;
                $attempt--;
                continue;
            }
            $retryAfter = self::retryAfter($response->getHeaderLine('Retry-After'), ($this->clock)());
            if ($attempt < $maxRetries && $this->retryable($method, $status, $code) && ($retryAfter === null || $retryAfter <= self::MAX_RETRY_AFTER_SECONDS)) {
                ($this->sleep)($retryAfter ?? $this->backoff($attempt));
                continue;
            }
            throw Errors::fromResponse($status, $body, self::requestId($response), $retryAfter);
        }
    }

    // ------------------------------------------------------------ helpers

    private static function isTimeout(TransferException $error): bool
    {
        // Guzzle 8 throws a timeout class; Guzzle 7 puts cURL's errno (28) in the handler context.
        foreach (['ConnectTimeoutException', 'NetworkTimeoutException', 'ResponseTimeoutException'] as $class) {
            if (is_a($error, 'GuzzleHttp\\Exception\\' . $class)) {
                return true;
            }
        }
        $context = method_exists($error, 'getHandlerContext') ? $error->getHandlerContext() : [];
        return (is_array($context) && ($context['errno'] ?? null) === 28) || stripos($error->getMessage(), 'timed out') !== false;
    }

    /** @param array<string, mixed> $query */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[$key] = match (true) {
                is_array($value) => implode(',', array_map('strval', $value)),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }
        return $pairs === [] ? '' : '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    /** Seconds from a Retry-After header (seconds, or an HTTP date). */
    private static function retryAfter(string $value, float $now): ?float
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value >= 0) {
            return (float) $value;
        }
        $at = strtotime($value);
        return $at === false ? null : max(0.0, $at - $now);
    }

    private static function filenameOf(string $disposition): ?string
    {
        if ($disposition === '') {
            return null;
        }
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $m) === 1) {
            return rawurldecode(trim($m[1]));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    private static function decode(string $text): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    private static function requestId(ResponseInterface $response): ?string
    {
        $id = $response->getHeaderLine('X-Request-Id');
        return $id === '' ? null : $id;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
