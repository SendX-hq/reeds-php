<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;

/** @phpstan-import-type Coverage from Types */
final class Coverages
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The states, zones and inter-state routes Reeds serves.
     *
     * @param array<string, mixed> $options
     * @return Coverage
     */
    public function get(array $options = []): array
    {
        return $this->client->get(Shipping::path('coverage'), [], $options);
    }
}
