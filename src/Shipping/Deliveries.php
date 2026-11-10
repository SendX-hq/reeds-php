<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;
use Reeds\Core\FileResponse;
use Reeds\Core\Page;

/**
 * @phpstan-import-type Delivery from Types
 * @phpstan-import-type BookFromQuote from Types
 * @phpstan-import-type DeliveryUpdate from Types
 * @phpstan-import-type CancelRequest from Types
 */
final class Deliveries
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Books the delivery a quote priced, and charges it. Sends an Idempotency-Key
     * (yours as `idempotency_key` in `$options`, or a new one).
     *
     * @param BookFromQuote $params
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->post(Shipping::path('deliveries'), $params, $options);
    }

    /**
     * Deliveries, newest first: one page, which `foreach` walks across every page.
     * Filters: `status`, `merchant_id`, `reference`, `created_from`, `created_to`,
     * `ids` (a list or comma-separated, up to 50), `limit`, `starting_after`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return Page<Delivery>
     */
    public function list(array $params = [], array $options = []): Page
    {
        /** @var Page<Delivery> */
        return $this->client->list(Shipping::path('deliveries'), $params, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function get(string $id, array $options = []): array
    {
        return $this->client->get(Shipping::path('deliveries', $id), [], $options);
    }

    /**
     * Changes the reference, instructions or metadata.
     *
     * @param DeliveryUpdate $params
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->client->patch(Shipping::path('deliveries', $id), $params, $options);
    }

    /**
     * Books and charges a priced delivery made in the dashboard (one waiting on BOOK, FUND_WALLET or PAY_INVOICE).
     *
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function book(string $id, array $options = []): array
    {
        return $this->client->post(Shipping::path('deliveries', $id, 'book'), null, $options);
    }

    /**
     * The item is packed: the pickup is scheduled from now. Only a paid delivery can be marked ready (409 otherwise).
     *
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function markReady(string $id, array $options = []): array
    {
        return $this->client->post(Shipping::path('deliveries', $id, 'ready'), null, $options);
    }

    /**
     * Cancels and refunds in full, until the rider sets off.
     *
     * @param CancelRequest $params
     * @param array<string, mixed> $options
     * @return Delivery
     */
    public function cancel(string $id, array $params = [], array $options = []): array
    {
        return $this->client->post(Shipping::path('deliveries', $id, 'cancel'), $params, $options);
    }

    /**
     * The shipping label, as a PDF.
     *
     * @param array<string, mixed> $options
     */
    public function label(string $id, array $options = []): FileResponse
    {
        return $this->client->file(Shipping::path('deliveries', $id, 'label'), $options);
    }
}
