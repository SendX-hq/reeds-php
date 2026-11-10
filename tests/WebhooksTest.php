<?php

declare(strict_types=1);

namespace Reeds\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reeds\Exception\WebhookSignatureException;
use Reeds\Reeds;
use Reeds\Webhooks;

/** The shared webhook vectors, made by the API's own signer, plus constructEvent. */
final class WebhooksTest extends TestCase
{
    /** @return array{tolerance: int, cases: list<array{name: string, payload: string, header: string, secrets: list<string>, now: int, valid: bool, error?: string}>} */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function outcome(callable $run): string
    {
        try {
            $run();
            return 'valid';
        } catch (WebhookSignatureException $error) {
            return $error->errorCode;
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> */
    public static function cases(): iterable
    {
        $vectors = self::vectors();
        foreach ($vectors['cases'] as $case) {
            yield $case['name'] => [$case, $vectors['tolerance']];
        }
    }

    /** @param array{name: string, payload: string, header: string, secrets: list<string>, now: int, valid: bool, error?: string} $case */
    #[DataProvider('cases')]
    public function testVector(array $case, int $tolerance): void
    {
        $expected = $case['valid'] ? 'valid' : $case['error'];
        $webhooks = new Webhooks();
        self::assertSame($expected, self::outcome(fn () => $webhooks->verifySignature($case['payload'], $case['header'], $case['secrets'], ['now' => $case['now'], 'tolerance' => $tolerance])));
    }

    public function testThereAreAllTwentyOneSharedVectors(): void
    {
        self::assertCount(21, self::vectors()['cases']);
    }

    /** @return array{name: string, payload: string, header: string, secrets: list<string>, now: int, valid: bool, error?: string} */
    private static function vector(string $name): array
    {
        foreach (self::vectors()['cases'] as $case) {
            if (str_starts_with($case['name'], $name)) {
                return $case;
            }
        }
        self::fail("No vector named {$name}.");
    }

    public function testConstructEventReturnsTheDecodedEventOnceTheSignatureChecksOut(): void
    {
        $valid = self::vector('valid');
        $event = (new Reeds())->webhooks->constructEvent($valid['payload'], $valid['header'], $valid['secrets'], ['now' => $valid['now']]);
        self::assertSame('delivery.booked', $event['type']);
        self::assertSame('dlv_9Kd3Qx7M', $event['data']['delivery']['id']);
    }

    public function testConstructEventReturnsAnEventTypeItDoesntKnowYet(): void
    {
        $future = self::vector('an event type');
        $event = (new Webhooks())->constructEvent($future['payload'], $future['header'], $future['secrets'], ['now' => $future['now']]);
        self::assertSame('order.paid', $event['type']);
    }

    public function testRefusesADecodedBodyAndSaysToPassTheRawOne(): void
    {
        $valid = self::vector('valid');
        $decoded = json_decode($valid['payload'], true);
        self::assertSame('RAW_BODY_REQUIRED', self::outcome(fn () => (new Webhooks())->constructEvent($decoded, $valid['header'], $valid['secrets'], ['now' => $valid['now']])));
    }

    public function testRefusesAClientSecretWhichNeverSignsWebhooks(): void
    {
        $valid = self::vector('valid');
        self::assertSame('WRONG_SECRET_TYPE', self::outcome(fn () => (new Webhooks())->verifySignature($valid['payload'], $valid['header'], 'rcs_test_xyz', ['now' => $valid['now']])));
    }

    public function testRefusesNoSecret(): void
    {
        $valid = self::vector('valid');
        self::assertSame('NO_SECRET', self::outcome(fn () => (new Webhooks())->verifySignature($valid['payload'], $valid['header'], '', ['now' => $valid['now']])));
        self::assertSame('NO_SECRET', self::outcome(fn () => (new Webhooks())->verifySignature($valid['payload'], $valid['header'], [], ['now' => $valid['now']])));
    }

    public function testChecksTheTimeAgainstTheClockByDefault(): void
    {
        $valid = self::vector('valid');
        $expected = abs(time() - $valid['now']) > 300 ? 'TIMESTAMP_OUTSIDE_TOLERANCE' : 'valid';
        self::assertSame($expected, self::outcome(fn () => (new Webhooks())->verifySignature($valid['payload'], $valid['header'], $valid['secrets'])));
    }

    public function testSendTestNeedsAClient(): void
    {
        $this->expectException(\LogicException::class);
        (new Webhooks())->sendTest();
    }
}
