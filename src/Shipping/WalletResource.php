<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;
use Reeds\Core\Page;

/**
 * @phpstan-import-type Wallet from Types
 * @phpstan-import-type WalletTransaction from Types
 * @phpstan-import-type FundWalletRequest from Types
 * @phpstan-import-type FundWalletResponse from Types
 * @phpstan-import-type ListWalletTransactionsQuery from Types
 */
final class WalletResource
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return Wallet
     */
    public function get(array $options = []): array
    {
        return $this->client->get(Shipping::path('wallet'), [], $options);
    }

    /**
     * Live mode: starts a Paystack payment and returns the `payment_url` to send the
     * payer to, and the `reference` that `wallet.funded` carries. In Test mode use
     * `$reeds->shipping->sandbox->fundWallet()`.
     *
     * @param FundWalletRequest $params
     * @param array<string, mixed> $options
     * @return FundWalletResponse
     */
    public function fund(array $params, array $options = []): array
    {
        return $this->client->post(Shipping::path('wallet', 'fund'), $params, $options);
    }

    /**
     * The wallet's ledger, newest first: one page, which `foreach` walks across every page.
     *
     * @param ListWalletTransactionsQuery $params
     * @param array<string, mixed> $options
     * @return Page<WalletTransaction>
     */
    public function listTransactions(array $params = [], array $options = []): Page
    {
        /** @var Page<WalletTransaction> */
        return $this->client->list(Shipping::path('wallet', 'transactions'), $params, $options);
    }
}
