<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;

/**
 * Address search: complete an address as your customer types, or read one from
 * a map pin. Free, within the account's allowance. Call it from your server.
 *
 * @phpstan-import-type Place from Types
 * @phpstan-import-type PlaceSuggestion from Types
 * @phpstan-import-type AutocompleteAddressesQuery from Types
 * @phpstan-import-type GetAddressDetailsQuery from Types
 * @phpstan-import-type ReverseGeocodeAddressQuery from Types
 * @phpstan-type PlaceSuggestions array{suggestions: list<PlaceSuggestion>, ...}
 */
final class Addresses
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Suggestions for what your customer has typed so far, best first (at most 6).
     * Send the same `session_token` on every call while one address is typed, and
     * on the details call that ends it: the whole search counts once.
     *
     * @param AutocompleteAddressesQuery $params
     * @param array<string, mixed> $options
     * @return PlaceSuggestions
     */
    public function autocomplete(array $params, array $options = []): array
    {
        return $this->client->get(Shipping::path('addresses', 'autocomplete'), $params, $options);
    }

    /**
     * The full address of a place: the suggestion your customer chose (send the
     * search's `session_token` to end that search), or any Google place id.
     *
     * @param GetAddressDetailsQuery $params
     * @param array<string, mixed> $options
     * @return Place
     */
    public function details(array $params, array $options = []): array
    {
        return $this->client->get(Shipping::path('addresses', 'details'), $params, $options);
    }

    /**
     * The address nearest a map pin in Nigeria. Each call is one search.
     *
     * @param ReverseGeocodeAddressQuery $params
     * @param array<string, mixed> $options
     * @return Place
     */
    public function reverse(array $params, array $options = []): array
    {
        return $this->client->get(Shipping::path('addresses', 'reverse'), $params, $options);
    }
}
