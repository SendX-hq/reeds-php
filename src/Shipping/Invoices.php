<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;
use Reeds\Core\FileResponse;
use Reeds\Core\Page;

/**
 * @phpstan-import-type Invoice from Types
 * @phpstan-import-type ListInvoicesQuery from Types
 */
final class Invoices
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Invoices, newest first (accounts billed monthly).
     *
     * @param ListInvoicesQuery $params
     * @param array<string, mixed> $options
     * @return Page<Invoice>
     */
    public function list(array $params = [], array $options = []): Page
    {
        /** @var Page<Invoice> */
        return $this->client->list(Shipping::path('invoices'), $params, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Invoice
     */
    public function get(string $id, array $options = []): array
    {
        return $this->client->get(Shipping::path('invoices', $id), [], $options);
    }

    /**
     * The invoice as a PDF.
     *
     * @param array<string, mixed> $options
     */
    public function pdf(string $id, array $options = []): FileResponse
    {
        return $this->client->file(Shipping::path('invoices', $id, 'pdf'), $options);
    }
}
