<?php

declare(strict_types=1);

namespace Reeds\Shipping;

use Reeds\Core\Client;

/** The Shipping API: `$reeds->shipping`. Quotes, deliveries, address search, the wallet, invoices and the sandbox. */
final class Shipping
{
    /** The Shipping API's base path. */
    public const PATH = '/shipping/v1';

    public readonly Coverages $coverage;
    public readonly Quotes $quotes;
    public readonly Deliveries $deliveries;
    public readonly Addresses $addresses;
    public readonly WalletResource $wallet;
    public readonly Invoices $invoices;
    public readonly Sandbox $sandbox;

    public function __construct(Client $client)
    {
        $this->coverage = new Coverages($client);
        $this->quotes = new Quotes($client);
        $this->deliveries = new Deliveries($client);
        $this->addresses = new Addresses($client);
        $this->wallet = new WalletResource($client);
        $this->invoices = new Invoices($client);
        $this->sandbox = new Sandbox($client);
    }

    /** @internal A path under /shipping/v1, each part escaped. */
    public static function path(string ...$parts): string
    {
        return self::PATH . implode('', array_map(static fn (string $part): string => '/' . rawurlencode($part), $parts));
    }
}
