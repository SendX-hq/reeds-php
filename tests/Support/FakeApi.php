<?php

declare(strict_types=1);

namespace Reeds\Tests\Support;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Reeds\Reeds;

/**
 * A fake API behind a real Guzzle stack: every request is recorded; the token
 * endpoint answers a fresh token unless told otherwise; everything else goes to
 * the handler. A handler returns a Response, or 'connection-failed' / 'timeout'
 * to simulate no response.
 */
final class FakeApi
{
    /** @var list<RequestInterface> */
    public array $calls = [];

    /** @var list<float> Seconds the client asked to wait between retries. */
    public array $sleeps = [];

    public float $now = 1_793_016_302.0;

    /** @var \Closure(RequestInterface): (Response|string) */
    private \Closure $handler;

    /** @var \Closure(RequestInterface): (Response|string) */
    private \Closure $token;

    /**
     * @param callable(RequestInterface): (Response|string) $handler
     * @param (callable(RequestInterface): (Response|string))|null $token
     */
    public function __construct(callable $handler, ?callable $token = null)
    {
        $this->handler = \Closure::fromCallable($handler);
        $this->token = \Closure::fromCallable($token ?? static fn (): Response => self::tokenResponse());
    }

    /** @param array<string, mixed> $options */
    public function client(array $options = []): Reeds
    {
        return new Reeds([
            'client_id' => 'rci_test_abc',
            'client_secret' => 'rcs_test_xyz',
            'http_client' => $this->guzzle(),
            '_sleep' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            '_random' => static fn (): float => 0.0,
            '_clock' => fn (): float => $this->now,
            ...$options,
        ]);
    }

    public function guzzle(): Guzzle
    {
        $handler = function (RequestInterface $request, array $options): PromiseInterface {
            $this->calls[] = $request;
            $answer = $request->getUri()->getPath() === '/oauth/token' ? ($this->token)($request) : ($this->handler)($request);
            return match (true) {
                $answer === 'connection-failed' => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect to api.getreeds.co', $request)),
                $answer === 'timeout' => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28])),
                $answer instanceof Response => Create::promiseFor($answer),
                default => throw new \LogicException('A fake handler must answer a Response, "connection-failed" or "timeout".'),
            };
        };
        return new Guzzle(['handler' => HandlerStack::create($handler)]);
    }

    /** @return list<RequestInterface> The calls to the API itself, not the token endpoint. */
    public function apiCalls(): array
    {
        return array_values(array_filter($this->calls, static fn (RequestInterface $r): bool => $r->getUri()->getPath() !== '/oauth/token'));
    }

    /** @return list<RequestInterface> */
    public function tokenCalls(): array
    {
        return array_values(array_filter($this->calls, static fn (RequestInterface $r): bool => $r->getUri()->getPath() === '/oauth/token'));
    }

    // ------------------------------------------------------------ answers

    /** @param array<string, string> $headers */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_8h2KdP', ...$headers], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public static function success(mixed $data, int $status = 200): Response
    {
        return self::json($status, ['status' => 'success', 'data' => $data]);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public static function failure(int $status, string $code, string $message = "Something's wrong.", array $extra = [], array $headers = []): Response
    {
        return self::json($status, ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message, 'doc_url' => "https://developer.getreeds.co/errors/{$code}", ...$extra]], $headers);
    }

    /** @param array<string, mixed> $extra */
    public static function tokenResponse(string $accessToken = 'tok_1', array $extra = []): Response
    {
        return self::json(200, ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'shipping', 'livemode' => false, ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function delivery(string $id, array $extra = []): array
    {
        return ['id' => $id, 'status' => 'scheduled', 'reference' => 'ORDER-10045', ...$extra];
    }

    /** Answers from a list, the last one repeated. */
    public static function sequence(Response|string ...$answers): \Closure
    {
        $i = 0;
        return static function () use (&$i, $answers): Response|string {
            return $answers[min($i++, count($answers) - 1)];
        };
    }
}
