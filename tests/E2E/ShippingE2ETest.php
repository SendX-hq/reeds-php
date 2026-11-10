<?php

declare(strict_types=1);

namespace Reeds\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Reeds\Exception\ConflictException;
use Reeds\Exception\NotFoundException;
use Reeds\Reeds;

/**
 * The library against the real API in Test mode: run nightly by
 * .github/workflows/e2e.yml, or by hand with `composer test:e2e`. Needs TEST
 * credentials of a dedicated account:
 *
 *     REEDS_E2E_CLIENT_ID=rci_test_... REEDS_E2E_CLIENT_SECRET=rcs_test_... composer test:e2e
 *
 * REEDS_E2E_BASE_URL picks the API (default https://api.getreeds.co). Test mode
 * never moves real money or sends a rider.
 */
final class ShippingE2ETest extends TestCase
{
    private const PICKUP = ['name' => 'Reeds SDK test', 'phone' => '08031234567', 'address' => '23 Allen Avenue', 'area' => 'Ikeja', 'lga' => 'Ikeja', 'state' => 'Lagos'];
    private const DROPOFF = ['name' => 'Reeds SDK receiver', 'phone' => '08098765432', 'address' => '14 Admiralty Way', 'area' => 'Lekki Phase 1', 'lga' => 'Eti-Osa', 'state' => 'Lagos'];
    private const PACKAGE = ['description' => 'SDK end-to-end test parcel', 'quantity' => 1, 'weight_kg' => 1];

    private Reeds $reeds;

    protected function setUp(): void
    {
        $id = getenv('REEDS_E2E_CLIENT_ID');
        $secret = getenv('REEDS_E2E_CLIENT_SECRET');
        if (!is_string($id) || $id === '' || !is_string($secret) || $secret === '') {
            self::markTestSkipped('Set REEDS_E2E_CLIENT_ID and REEDS_E2E_CLIENT_SECRET (test credentials) to run.');
        }
        $baseUrl = getenv('REEDS_E2E_BASE_URL');
        $this->reeds = new Reeds(['client_id' => $id, 'client_secret' => $secret, 'base_url' => is_string($baseUrl) ? $baseUrl : null]);
    }

    public function testUsesTestCredentials(): void
    {
        self::assertSame('test', $this->reeds->mode);
        self::assertContains('shipping', $this->reeds->scopes());
    }

    public function testQuoteBookNotReadyMarkReadyAdvanceToDeliveredLabel(): void
    {
        $s = $this->reeds->shipping;
        $s->sandbox->fundWallet(['amount' => 1_000_000]);
        $quote = $s->quotes->create(['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF, 'package' => self::PACKAGE]);
        self::assertTrue($quote['available'], "Reeds didn't quote the test route: " . json_encode($quote));

        $reference = 'SDK-PHP-E2E-' . time();
        $made = $s->deliveries->create(['quote_id' => $quote['id'], 'reference' => $reference, 'ready' => false]);
        self::assertSame('requires_action', $made['status']);
        self::assertSame(['MARK_READY'], array_column($made['required_actions'], 'type'));

        self::assertSame('scheduled', $s->deliveries->markReady($made['id'])['status']);
        self::assertSame('delivered', $s->sandbox->advanceDelivery($made['id'], ['status' => 'delivered'])['status']);
        self::assertSame([$made['id']], array_column($s->deliveries->list(['reference' => $reference])->data, 'id'));
        self::assertSame('%PDF', substr($s->deliveries->label($made['id'])->data, 0, 4));
    }

    public function testErrorsCarryTheApisCodeAndRequestId(): void
    {
        $s = $this->reeds->shipping;
        try {
            $s->deliveries->get('dlv_00000000-0000-0000-0000-000000000000');
            self::fail('Expected NOT_FOUND.');
        } catch (NotFoundException $error) {
            self::assertNotEmpty($error->requestId);
        }
        $quote = $s->quotes->create(['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF, 'package' => self::PACKAGE]);
        $made = $s->deliveries->create(['quote_id' => $quote['id'], 'ready' => false]);
        $s->deliveries->cancel($made['id'], ['reason' => 'SDK end-to-end test']);
        $this->expectException(ConflictException::class);
        $s->deliveries->markReady($made['id']);
    }
}
