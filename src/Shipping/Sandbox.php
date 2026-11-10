<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;
use Reeds\Exception\PermissionDeniedException;

/**
 * Test mode only. Refused here, before any request, with live credentials.
 *
 * @phpstan-import-type Wallet from Types
 * @phpstan-import-type Delivery from Types
 * @phpstan-import-type FundWalletRequest from Types
 * @phpstan-import-type AdvanceRequest from Types
 */
final class Sandbox
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Tops up the test wallet at once, and books deliveries made in the dashboard that waited for funds.
     *
     * @param FundWalletRequest $params
     * @param array<string, mixed> $options
     * @return Wallet
     */
    public function fundWallet(array $params, array $options = []): array
    {
        $this->testOnly();
        return $this->client->post(Shipping::path('sandbox', 'wallet', 'fund'), $params, $options);
    }

    /**
     * Moves a test delivery to the next status, or to the one you name.
     *
     * @param AdvanceRequest $params
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function advanceDelivery(string $id, array $params = [], array $options = []): array
    {
        $this->testOnly();
        return $this->client->post(Shipping::path('sandbox', 'deliveries', $id, 'advance'), $params, $options);
    }

    private function testOnly(): void
    {
        if ($this->client->mode === 'live') {
            throw new PermissionDeniedException('TEST_MODE_ONLY', 'Sandbox calls work with test credentials (rci_test_...) only.');
        }
    }
}
