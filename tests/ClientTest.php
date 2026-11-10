<?php

declare(strict_types=1);

namespace Reeds\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Reeds\Exception\ApiException;
use Reeds\Exception\AuthenticationException;
use Reeds\Exception\ConflictException;
use Reeds\Exception\ConnectionException;
use Reeds\Exception\ConnectionTimeoutException;
use Reeds\Exception\InvalidRequestException;
use Reeds\Exception\NotFoundException;
use Reeds\Exception\PaymentRequiredException;
use Reeds\Exception\PermissionDeniedException;
use Reeds\Exception\RateLimitException;
use Reeds\Exception\ReedsException;
use Reeds\Exception\ServiceUnavailableException;
use Reeds\Reeds;
use Reeds\Tests\Support\FakeApi;
use Reeds\Version;

final class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('REEDS_CLIENT_ID');
        putenv('REEDS_CLIENT_SECRET');
        unset($_ENV['REEDS_CLIENT_ID'], $_ENV['REEDS_CLIENT_SECRET'], $_SERVER['REEDS_CLIENT_ID'], $_SERVER['REEDS_CLIENT_SECRET']);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /** Runs $call and returns what it threw. */
    private static function thrown(callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $error) {
            return $error;
        }
        self::fail('Expected an exception.');
    }

    // ------------------------------------------------------------ credentials and the token

    public function testFetchesOneTokenWithTheCredentialsFormEncodedAndReusesIt(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(FakeApi::delivery('dlv_1')));
        $reeds = $api->client();
        $reeds->shipping->deliveries->get('dlv_1');
        $reeds->shipping->deliveries->get('dlv_1');
        self::assertCount(1, $api->tokenCalls());
        $token = $api->tokenCalls()[0];
        self::assertSame('https://api.getreeds.co/oauth/token', (string) $token->getUri());
        self::assertSame('application/x-www-form-urlencoded', $token->getHeaderLine('Content-Type'));
        parse_str((string) $token->getBody(), $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'rci_test_abc', 'client_secret' => 'rcs_test_xyz'], $form);
        self::assertSame('', $token->getHeaderLine('Idempotency-Key'));
        foreach ($api->apiCalls() as $call) {
            self::assertSame('Bearer tok_1', $call->getHeaderLine('Authorization'));
        }
    }

    public function testRefreshesATokenAMinuteBeforeItExpires(): void
    {
        $n = 0;
        $api = new FakeApi(static fn () => FakeApi::success(FakeApi::delivery('dlv_1')), static function () use (&$n) {
            return FakeApi::tokenResponse('tok_' . ++$n);
        });
        $reeds = $api->client();
        $start = $api->now;
        $reeds->shipping->deliveries->get('dlv_1');
        $api->now = $start + 58 * 60;
        $reeds->shipping->deliveries->get('dlv_1');
        self::assertCount(1, $api->tokenCalls());
        $api->now = $start + 59 * 60 + 30;
        $reeds->shipping->deliveries->get('dlv_1');
        self::assertCount(2, $api->tokenCalls());
        $calls = $api->apiCalls();
        self::assertSame('Bearer tok_2', end($calls)->getHeaderLine('Authorization'));
    }

    public function testAnExpiredTokenGetsOneFreshTokenAndOneMoreTryNotCountedAsARetry(): void
    {
        $n = 0;
        $api = new FakeApi(
            FakeApi::sequence(FakeApi::failure(401, 'TOKEN_EXPIRED', 'The access token has expired.'), FakeApi::success(FakeApi::delivery('dlv_1'))),
            static function () use (&$n) {
                return FakeApi::tokenResponse('tok_' . ++$n);
            },
        );
        $found = $api->client(['max_retries' => 0])->shipping->deliveries->get('dlv_1');
        self::assertSame('dlv_1', $found['id']);
        self::assertSame(['Bearer tok_1', 'Bearer tok_2'], array_map(static fn ($c) => $c->getHeaderLine('Authorization'), $api->apiCalls()));
    }

    public function testASecond401InARowIsAnAuthenticationException(): void
    {
        $api = new FakeApi(static fn () => FakeApi::failure(401, 'UNAUTHENTICATED', "The access token isn't valid."));
        $error = self::thrown(fn () => $api->client()->shipping->deliveries->get('dlv_1'));
        self::assertInstanceOf(AuthenticationException::class, $error);
        self::assertCount(2, $api->apiCalls());
        self::assertCount(2, $api->tokenCalls());
    }

    public function testCredentialsTheTokenEndpointRefusesAreAnAuthenticationExceptionWithTheOAuthCode(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]), static fn () => FakeApi::json(401, ['error' => 'invalid_client', 'error_description' => 'The client id or secret is wrong.']));
        $error = self::thrown(fn () => $api->client()->shipping->coverage->get());
        self::assertInstanceOf(AuthenticationException::class, $error);
        self::assertSame(401, $error->status);
        self::assertSame('invalid_client', $error->errorCode);
        self::assertSame('The client id or secret is wrong.', $error->getMessage());
        self::assertSame('req_8h2KdP', $error->requestId);
        self::assertCount(0, $api->apiCalls());
    }

    public function testWithoutCredentialsACallSaysWhereToFindThemAndWebhooksStillWork(): void
    {
        $reeds = new Reeds(['http_client' => (new FakeApi(static fn () => FakeApi::success([])))->guzzle()]);
        $error = self::thrown(fn () => $reeds->shipping->coverage->get());
        self::assertInstanceOf(AuthenticationException::class, $error);
        self::assertSame('MISSING_CREDENTIALS', $error->errorCode);
        self::assertStringContainsString('REEDS_CLIENT_ID', $error->getMessage());
        self::assertNull($reeds->mode);
        self::assertTrue(method_exists($reeds->webhooks, 'constructEvent'));
    }

    public function testReadsTheEnvironmentAndTheModeFromTheClientId(): void
    {
        putenv('REEDS_CLIENT_ID=rci_live_envid');
        putenv('REEDS_CLIENT_SECRET=rcs_live_envsecret');
        $api = new FakeApi(static fn () => FakeApi::success([]));
        $reeds = new Reeds(['http_client' => $api->guzzle()]);
        self::assertSame('live', $reeds->mode);
        $reeds->shipping->coverage->get();
        parse_str((string) $api->tokenCalls()[0]->getBody(), $form);
        self::assertSame('rci_live_envid', $form['client_id']);
        self::assertSame('test', $api->client()->mode);
    }

    public function testScopesListsTheScopesTheTokenCarries(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]), static fn () => FakeApi::tokenResponse('tok_1', ['scope' => 'shipping store']));
        self::assertSame(['shipping', 'store'], $api->client()->scopes());
    }

    public function testNeverPutsTheSecretInAnError(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]), static fn () => FakeApi::json(400, ['error' => 'invalid_request', 'error_description' => 'grant_type is required.']));
        $error = self::thrown(fn () => $api->client()->shipping->coverage->get());
        self::assertInstanceOf(ReedsException::class, $error);
        self::assertStringNotContainsString('rcs_test_xyz', $error->getMessage() . json_encode($error->raw));
    }

    // ------------------------------------------------------------ requests

    public function testSendsTheUserAgentAcceptAndDefaultHeadersToTheBaseUrl(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]));
        $api->client(['base_url' => 'https://api.example.test/', 'default_headers' => ['X-Team' => 'ops']])->shipping->coverage->get();
        $call = $api->apiCalls()[0];
        self::assertSame('https://api.example.test/shipping/v1/coverage', (string) $call->getUri());
        self::assertSame('reeds-php/' . Version::VERSION . ' php/' . PHP_VERSION, $call->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $call->getHeaderLine('Accept'));
        self::assertSame('ops', $call->getHeaderLine('X-Team'));
        self::assertSame('https://api.example.test/oauth/token', (string) $api->tokenCalls()[0]->getUri());
    }

    public function testAnEmptyBaseUrlMeansTheDefault(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]));
        $api->client(['base_url' => ''])->shipping->coverage->get();
        self::assertSame('api.getreeds.co', $api->apiCalls()[0]->getUri()->getHost());
        self::assertSame('https://api.getreeds.co/oauth/token', (string) $api->tokenCalls()[0]->getUri());
    }

    public function testGivesEveryPostAnIdempotencyKeyAndNoneToAGetOrPatch(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(FakeApi::delivery('dlv_1')));
        $reeds = $api->client();
        $reeds->shipping->deliveries->markReady('dlv_1');
        $reeds->shipping->deliveries->markReady('dlv_1');
        $reeds->shipping->deliveries->get('dlv_1');
        $reeds->shipping->deliveries->update('dlv_1', ['reference' => 'R2']);
        [$first, $second, $get, $patch] = $api->apiCalls();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->getHeaderLine('Idempotency-Key'));
        self::assertNotSame($first->getHeaderLine('Idempotency-Key'), $second->getHeaderLine('Idempotency-Key'));
        self::assertSame('', $get->getHeaderLine('Idempotency-Key'));
        self::assertSame('', $patch->getHeaderLine('Idempotency-Key'));
    }

    public function testUsesYourIdempotencyKeyWhenYouPassOne(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(FakeApi::delivery('dlv_1'), 201));
        $api->client()->shipping->deliveries->create(['quote_id' => 'qt_1'], ['idempotency_key' => 'order-10045-delivery']);
        self::assertSame('order-10045-delivery', $api->apiCalls()[0]->getHeaderLine('Idempotency-Key'));
    }

    public function testReturnsTheEnvelopesDataAndKeepsWhatItDoesntKnow(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(['id' => 'dlv_1', 'status' => 'with_partner', 'new_field' => ['a' => 1]]));
        self::assertSame(['id' => 'dlv_1', 'status' => 'with_partner', 'new_field' => ['a' => 1]], $api->client()->shipping->deliveries->get('dlv_1'));
    }

    public function testA2xxBodyThatIsntTheEnvelopeIsAnApiException(): void
    {
        $api = new FakeApi(static fn () => new Response(200, ['X-Request-Id' => 'req_x'], '<html>ok</html>'));
        $error = self::thrown(fn () => $api->client()->shipping->coverage->get());
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame('UNEXPECTED_RESPONSE', $error->errorCode);
        self::assertSame('req_x', $error->requestId);
    }

    public function testGivesUpOnATimedOutAttemptAfterTheRetries(): void
    {
        $api = new FakeApi(static fn () => 'timeout');
        $error = self::thrown(fn () => $api->client(['max_retries' => 1, 'timeout' => 5])->shipping->coverage->get());
        self::assertInstanceOf(ConnectionTimeoutException::class, $error);
        self::assertSame('TIMEOUT', $error->errorCode);
        self::assertCount(2, $api->apiCalls());
    }

    // ------------------------------------------------------------ retries

    public function testRetriesA429AfterRetryAfterWithTheSameIdempotencyKey(): void
    {
        $api = new FakeApi(FakeApi::sequence(FakeApi::failure(429, 'RATE_LIMITED', 'Too many requests.', [], ['Retry-After' => '3']), FakeApi::success(FakeApi::delivery('dlv_1'), 201)));
        $created = $api->client()->shipping->deliveries->create(['quote_id' => 'qt_1']);
        self::assertSame('dlv_1', $created['id']);
        [$first, $second] = $api->apiCalls();
        self::assertSame($first->getHeaderLine('Idempotency-Key'), $second->getHeaderLine('Idempotency-Key'));
        self::assertSame([3.0], $api->sleeps);
    }

    public function testRetries502503And504BackingOffHalfASecondThenASecond(): void
    {
        $api = new FakeApi(FakeApi::sequence(FakeApi::failure(502, 'INTERNAL_ERROR'), FakeApi::failure(503, 'SERVICE_UNAVAILABLE'), FakeApi::success([])));
        $api->client()->shipping->coverage->get();
        self::assertCount(3, $api->apiCalls());
        self::assertSame([0.5, 1.0], $api->sleeps);
    }

    public function testRetriesA500OnAGetNeverOnAPost(): void
    {
        $get = new FakeApi(FakeApi::sequence(FakeApi::failure(500, 'INTERNAL_ERROR'), FakeApi::success([])));
        $get->client()->shipping->coverage->get();
        self::assertCount(2, $get->apiCalls());

        $post = new FakeApi(FakeApi::sequence(FakeApi::failure(500, 'INTERNAL_ERROR'), FakeApi::success([])));
        self::assertInstanceOf(ApiException::class, self::thrown(fn () => $post->client()->shipping->deliveries->book('dlv_1')));
        self::assertCount(1, $post->apiCalls());
    }

    public function testRetries409RequestInProgressButNotAnother409(): void
    {
        $running = new FakeApi(FakeApi::sequence(FakeApi::failure(409, 'REQUEST_IN_PROGRESS'), FakeApi::success(FakeApi::delivery('dlv_1'))));
        $running->client()->shipping->deliveries->book('dlv_1');
        self::assertCount(2, $running->apiCalls());

        $booked = new FakeApi(static fn () => FakeApi::failure(409, 'ACTION_NOT_ALLOWED', 'This delivery is already booked.'));
        self::assertInstanceOf(ConflictException::class, self::thrown(fn () => $booked->client()->shipping->deliveries->book('dlv_1')));
        self::assertCount(1, $booked->apiCalls());
    }

    public function testRetriesADroppedConnectionThenGivesUpWithAConnectionException(): void
    {
        $api = new FakeApi(static fn () => 'connection-failed');
        $error = self::thrown(fn () => $api->client()->shipping->coverage->get());
        self::assertInstanceOf(ConnectionException::class, $error);
        self::assertNotInstanceOf(ConnectionTimeoutException::class, $error);
        self::assertSame('CONNECTION_FAILED', $error->errorCode);
        self::assertCount(3, $api->apiCalls());
    }

    public function testStopsAfterMaxRetriesAndThrowsTheLastErrorWithRetryAfter(): void
    {
        $api = new FakeApi(static fn () => FakeApi::failure(503, 'SERVICE_UNAVAILABLE', 'Reeds is busy.', [], ['Retry-After' => '2']));
        $error = self::thrown(fn () => $api->client(['max_retries' => 1])->shipping->coverage->get());
        self::assertInstanceOf(ServiceUnavailableException::class, $error);
        self::assertSame(2.0, $error->retryAfter);
        self::assertCount(2, $api->apiCalls());
    }

    public function testDoesntWaitOutARetryAfterLongerThan30Seconds(): void
    {
        $api = new FakeApi(static fn () => FakeApi::failure(429, 'RATE_LIMITED', 'Slow down.', [], ['Retry-After' => '120']));
        $error = self::thrown(fn () => $api->client()->shipping->quotes->create(['pickup' => [], 'dropoff' => []]));
        self::assertInstanceOf(RateLimitException::class, $error);
        self::assertSame(120.0, $error->retryAfter);
        self::assertCount(1, $api->apiCalls());
        self::assertSame([], $api->sleeps);
    }

    public function testMaxRetriesPerRequestOverridesTheClients(): void
    {
        $api = new FakeApi(static fn () => FakeApi::failure(503, 'SERVICE_UNAVAILABLE'));
        self::thrown(fn () => $api->client()->shipping->coverage->get(['max_retries' => 0]));
        self::assertCount(1, $api->apiCalls());
    }

    public function testNeverRetries400402403404Or422(): void
    {
        foreach ([[400, 'IDEMPOTENCY_KEY_REQUIRED'], [402, 'INSUFFICIENT_BALANCE'], [403, 'INSUFFICIENT_SCOPE'], [404, 'NOT_FOUND'], [422, 'INVALID_REQUEST']] as [$status, $code]) {
            $api = new FakeApi(static fn () => FakeApi::failure($status, $code));
            self::thrown(fn () => $api->client()->shipping->deliveries->get('dlv_1'));
            self::assertCount(1, $api->apiCalls(), (string) $status);
        }
    }

    // ------------------------------------------------------------ errors

    public function testMapsEachStatusToItsClassWithTheCodeFieldsDocUrlAndRequestId(): void
    {
        $cases = [
            [400, 'IDEMPOTENCY_KEY_REQUIRED', InvalidRequestException::class],
            [402, 'INSUFFICIENT_BALANCE', PaymentRequiredException::class],
            [403, 'INSUFFICIENT_SCOPE', PermissionDeniedException::class],
            [404, 'NOT_FOUND', NotFoundException::class],
            [409, 'QUOTE_ALREADY_USED', ConflictException::class],
            [422, 'INVALID_REQUEST', InvalidRequestException::class],
        ];
        foreach ($cases as [$status, $code, $class]) {
            $api = new FakeApi(static fn () => FakeApi::failure($status, $code, 'Nope.', ['fields' => [['field' => 'dropoff.lga', 'message' => 'Enter the LGA.']], 'delivery_id' => 'dlv_9']));
            $error = self::thrown(fn () => $api->client()->shipping->deliveries->get('dlv_1'));
            self::assertInstanceOf($class, $error, $code);
            self::assertInstanceOf(ReedsException::class, $error);
            self::assertSame($status, $error->status);
            self::assertSame($status, $error->getCode());
            self::assertSame($code, $error->errorCode);
            self::assertSame('Nope.', $error->getMessage());
            self::assertSame("https://developer.getreeds.co/errors/{$code}", $error->docUrl);
            self::assertSame('req_8h2KdP', $error->requestId);
            self::assertSame([['field' => 'dropoff.lga', 'message' => 'Enter the LGA.']], $error->fields);
            self::assertSame('dlv_9', $error->raw['delivery_id'] ?? null);
        }
    }

    public function testAFailureWithoutAJsonBodyStillSaysTheStatus(): void
    {
        $api = new FakeApi(static fn () => new Response(413, [], '<html>413 Request Entity Too Large</html>'));
        $error = self::thrown(fn () => $api->client()->shipping->coverage->get());
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame(413, $error->status);
        self::assertSame('HTTP_413', $error->errorCode);
    }
}
