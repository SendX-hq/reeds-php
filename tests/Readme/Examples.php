<?php

declare(strict_types=1);

namespace Reeds\Tests\Readme;

use Reeds\Exception\InvalidRequestException;
use Reeds\Exception\PaymentRequiredException;
use Reeds\Exception\ReedsException;
use Reeds\Exception\WebhookSignatureException;
use Reeds\Reeds;
use Reeds\Webhooks;

/**
 * The README's examples as real code (never run): PHPStan checks them against
 * the library's types, so an example can't drift from the API.
 *
 * @phpstan-import-type Delivery from \Reeds\Shipping\Types
 */
final class Examples
{
    public function quickstart(): void
    {
        $reeds = new Reeds();
        $quote = $reeds->shipping->quotes->create([
            'pickup' => ['name' => 'Tola Fabrics', 'phone' => '08051234567', 'address' => '23 Allen Avenue', 'area' => 'Ikeja', 'lga' => 'Ikeja', 'state' => 'Lagos'],
            'dropoff' => ['name' => 'Chinedu Eze', 'phone' => '08098765432', 'address' => '14 Admiralty Way', 'area' => 'Lekki Phase 1', 'lga' => 'Eti-Osa', 'state' => 'Lagos'],
            'package' => ['description' => '2 pairs of sneakers', 'quantity' => 1, 'weight_kg' => 1.5],
        ]);
        if (!$quote['available']) {
            exit($quote['reason']['message']);
        }
        echo $quote['fee']['total'];
        $delivery = $reeds->shipping->deliveries->create(['quote_id' => $quote['id'], 'reference' => 'ORDER-10045']);
        $latest = $reeds->shipping->deliveries->get($delivery['id']);
        echo $latest['status'], ' ', $latest['tracking_url'] ?? '';

        $notReady = $reeds->shipping->deliveries->create(['quote_id' => $quote['id'], 'ready' => false]);
        $reeds->shipping->deliveries->markReady($notReady['id']);

        $coverage = $reeds->shipping->coverage->get();
        echo count($coverage['states'] ?? []);

        $label = $reeds->shipping->deliveries->label($delivery['id']);
        file_put_contents($label->filename ?? 'label.pdf', $label->data);

        $wallet = $reeds->shipping->wallet->get();
        echo $wallet['balance'];
        $topUp = $reeds->shipping->wallet->fund(['amount' => 5_000_000]);
        header('Location: ' . ($topUp['payment_url'] ?? ''));
        foreach ($reeds->shipping->wallet->listTransactions() as $entry) {
            echo $entry['type'] ?? '', ' ', $entry['amount'] ?? 0, ' ', $entry['description'] ?? '', PHP_EOL;
        }
        $invoices = $reeds->shipping->invoices->list(['status' => 'issued']);
        $reeds->shipping->invoices->pdf($invoices->data[0]['id'] ?? '');

        $reeds->shipping->sandbox->fundWallet(['amount' => 1_000_000]);
        $reeds->shipping->sandbox->advanceDelivery($delivery['id']);
        $reeds->shipping->sandbox->advanceDelivery($delivery['id'], ['status' => 'delivered']);

        $page = $reeds->shipping->deliveries->list(['status' => 'delivered', 'limit' => 50]);
        echo count($page->data), $page->hasMore ? 'more' : '';
        $page->nextPage();

        try {
            $reeds->shipping->deliveries->create(['quote_id' => $quote['id']], ['idempotency_key' => 'order-10045-delivery']);
        } catch (PaymentRequiredException $e) {
            echo $e->errorCode;
        } catch (InvalidRequestException $e) {
            print_r($e->fields);
        } catch (ReedsException $e) {
            error_log("{$e->errorCode}: {$e->getMessage()} (request {$e->requestId}, see {$e->docUrl})");
            throw $e;
        }
        $reeds->shipping->deliveries->list(['limit' => 10], ['timeout' => 10, 'max_retries' => 0]);
        $reeds->webhooks->sendTest(['type' => 'delivery.booked']);
        echo $reeds->mode, implode(',', $reeds->scopes());
    }

    public function plainWebhook(): void
    {
        $webhooks = new Webhooks();
        try {
            $event = $webhooks->constructEvent((string) file_get_contents('php://input'), $_SERVER['HTTP_REEDS_SIGNATURE'] ?? null, (string) getenv('REEDS_WEBHOOK_SECRET'));
        } catch (WebhookSignatureException $e) {
            http_response_code(400);
            exit($e->getMessage());
        }
        echo $event['type'];
    }

    /** @param Delivery $delivery */
    public function isDone(array $delivery): bool
    {
        return in_array($delivery['status'], ['delivered', 'cancelled'], true);
    }
}
