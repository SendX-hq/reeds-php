<?php

declare(strict_types=1);

namespace Reeds;

use Reeds\Core\Client;
use Reeds\Shipping\Shipping;

/**
 * The Reeds API client. One client, one set of credentials, every Reeds API:
 *
 *     $reeds = new Reeds\Reeds(['client_id' => '...', 'client_secret' => '...']);
 *     $quote = $reeds->shipping->quotes->create([...]);
 *
 * Options: client_id, client_secret (default: the REEDS_CLIENT_ID and
 * REEDS_CLIENT_SECRET environment variables), base_url (default
 * https://api.getreeds.co), timeout (seconds per attempt, default 60),
 * max_retries (default 2), http_client (a Guzzle client), default_headers.
 */
final class Reeds
{
    /** The Shipping API: quotes, deliveries, the wallet, invoices and the sandbox. */
    public readonly Shipping $shipping;

    /** Webhook signature checks and test events, for every API. */
    public readonly Webhooks $webhooks;

    /** "test" or "live", from the client id; null without one. */
    public readonly ?string $mode;

    private readonly Client $client;

    /**
     * @param array{
     *     client_id?: string|null,
     *     client_secret?: string|null,
     *     base_url?: string|null,
     *     timeout?: float|int,
     *     max_retries?: int,
     *     http_client?: \GuzzleHttp\ClientInterface,
     *     default_headers?: array<string, string>,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->client = new Client($options);
        $this->shipping = new Shipping($this->client);
        $this->webhooks = new Webhooks($this->client);
        $this->mode = $this->client->mode;
    }

    /** The Shipping API, as a method too (for the Laravel facade: `Reeds::shipping()->deliveries->create(...)`). */
    public function shipping(): Shipping
    {
        return $this->shipping;
    }

    /** The webhooks helper, as a method too (for the Laravel facade). */
    public function webhooks(): Webhooks
    {
        return $this->webhooks;
    }

    /**
     * The scopes your credentials carry: one per Reeds API your account may use (for example `['shipping']`).
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->client->scopes();
    }
}
