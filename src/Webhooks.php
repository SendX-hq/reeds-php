<?php

declare(strict_types=1);

namespace Reeds;

use Reeds\Core\Client;
use Reeds\Core\Signature;
use Reeds\Exception\WebhookSignatureException;
use Reeds\Shipping\Shipping;

/**
 * Webhooks are account-wide: one endpoint per account and mode gets every
 * Reeds API's events, signed the same way.
 *
 * Without a client (`new Reeds\Webhooks()`) it verifies events; `sendTest()`
 * needs one (`$reeds->webhooks`).
 */
final class Webhooks
{
    public function __construct(private readonly ?Client $client = null)
    {
    }

    /**
     * Throws WebhookSignatureException unless `$header` signs `$payload` (the raw
     * body) with `$secret`, within 5 minutes. `$secret` can be a list (both
     * secrets while you rotate one).
     *
     * @param string $payload The raw request body.
     * @param string|list<string> $secret
     * @param array{tolerance?: int, now?: int} $options
     */
    public function verifySignature(mixed $payload, ?string $header, string|array $secret, array $options = []): void
    {
        Signature::verify($payload, $header, $secret, $options);
    }

    /**
     * Verifies the signature, then returns the decoded event:
     * `['id' => 'evt_...', 'type' => 'delivery.booked', 'created_at' => ..., 'livemode' => ..., 'data' => [...]]`.
     * Reeds adds event types over time: ignore the ones you don't use.
     *
     * @param string $payload The raw request body.
     * @param string|list<string> $secret
     * @param array{tolerance?: int, now?: int} $options
     * @return array<string, mixed>
     */
    public function constructEvent(mixed $payload, ?string $header, string|array $secret, array $options = []): array
    {
        Signature::verify($payload, $header, $secret, $options);
        /** @var string $payload */
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookSignatureException('INVALID_PAYLOAD', "The signed body isn't JSON.");
        }
        if (!is_array($event)) {
            throw new WebhookSignatureException('INVALID_PAYLOAD', "The signed body isn't a JSON object.");
        }
        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * Sends a signed test event to the endpoint saved for this mode (Dashboard → Developers).
     *
     * @param array{type?: string} $params
     * @param array<string, mixed> $options
     * @return array{event_id?: string, type?: string, sent?: bool}
     */
    public function sendTest(array $params = [], array $options = []): array
    {
        if ($this->client === null) {
            throw new \LogicException('sendTest needs a client: use (new Reeds\Reeds([...]))->webhooks->sendTest().');
        }
        /** @var array{event_id?: string, type?: string, sent?: bool} */
        return $this->client->post(Shipping::PATH . '/webhooks/test', $params, $options);
    }
}
