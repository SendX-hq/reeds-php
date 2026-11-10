<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;

/**
 * @phpstan-import-type QuoteRequest from Types
 * @phpstan-import-type Quote from Types
 * @phpstan-import-type QuoteUnavailable from Types
 */
final class Quotes
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Prices a delivery. A route Reeds doesn't serve is not an error: the answer
     * has `available => false` and a `reason`.
     *
     * @param QuoteRequest $params
     * @param array<string, mixed> $options
     * @return Quote|QuoteUnavailable
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->post(Shipping::path('quotes'), $params, $options);
    }
}
