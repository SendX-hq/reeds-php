<p align="center"><img src="https://raw.githubusercontent.com/SendX-hq/reeds-php/main/assets/reeds-logo.png" alt="Reeds" width="240"></p>

<h1 align="center">Reeds PHP library</h1>

<p align="center">
  The official PHP library for the Reeds APIs: one client for every Reeds service.
</p>

<p align="center">
  <a href="https://packagist.org/packages/reeds/reeds-php"><img src="https://img.shields.io/packagist/v/reeds/reeds-php?color=0066F5&label=packagist" alt="Packagist version"></a>
  <a href="https://github.com/SendX-hq/reeds-php/actions/workflows/ci.yml"><img src="https://github.com/SendX-hq/reeds-php/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <img src="https://img.shields.io/packagist/dependency-v/reeds/reeds-php/php?color=0066F5" alt="PHP version">
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/reeds/reeds-php?color=0066F5" alt="MIT license"></a>
</p>

<p align="center">
  <a href="https://developer.getreeds.co">Developer docs</a> ·
  <a href="https://developer.getreeds.co/api">API reference</a> ·
  <a href="https://developer.getreeds.co/changelog">API changelog</a> ·
  <a href="CHANGELOG.md">Library changelog</a>
</p>

---

```php
$reeds = new Reeds\Reeds(); // reads REEDS_CLIENT_ID and REEDS_CLIENT_SECRET

$quote = $reeds->shipping->quotes->create(['pickup' => $pickup, 'dropoff' => $dropoff, 'package' => ['description' => 'Sneakers', 'quantity' => 1, 'weight_kg' => 1.5]]);
if ($quote['available']) {
    $delivery = $reeds->shipping->deliveries->create(['quote_id' => $quote['id'], 'reference' => 'ORDER-10045']);
    echo $delivery['tracking_code']; // HQ3W5F2
}
```

## Contents

- [Services](#services)
- [Why use the library](#why-use-the-library)
- [Install](#install)
- [Credentials and modes](#credentials-and-modes)
- [Shipping](#shipping): [quickstart](#quickstart), [coverage and quotes](#coverage-and-quotes), [deliveries](#deliveries), [statuses](#delivery-statuses), [labels](#labels), [wallet and invoices](#wallet-and-invoices), [sandbox](#test-mode-and-the-sandbox)
- [Webhooks](#webhooks)
- [Laravel](#laravel)
- [Lists and pagination](#lists-and-pagination)
- [Errors](#errors)
- [Retries, idempotency and timeouts](#retries-idempotency-and-timeouts)
- [Types and static analysis](#types-and-static-analysis)
- [Configuration](#configuration)
- [Versioning](#versioning) · [Support](#support) · [License](#license)

## Services

Reeds offers its services as separate APIs. The library gives each one a property on the same client, so one install and one set of credentials cover them all:

| Service | On the client | Status |
|---|---|---|
| **Shipping**: quotes, deliveries, labels, the wallet, invoices, the sandbox | `$reeds->shipping` | **Available** |
| **Store**: products, orders and storefronts for Reeds Stores | `$reeds->store` | Coming soon |

Webhooks are shared: one endpoint receives every service's events, all verified by `$reeds->webhooks`. A new service arrives in a minor version of this library, so upgrading never breaks the services you already use.

## Why use the library

| | |
|---|---|
| **Authentication handled** | It exchanges your client id and secret for an access token, caches it, and refreshes it before it expires. |
| **Safe retries** | Every `POST` carries an `Idempotency-Key`, and a retry reuses it, so a retry never books or charges twice. |
| **Resilient** | It retries rate limits, overload and dropped connections, honouring `Retry-After`. |
| **Typed** | It ships PHPStan and Psalm array shapes for every object, generated from the API's OpenAPI spec. |
| **Pagination** | `foreach` walks every page of a list. |
| **Clear errors** | One exception class per kind of failure, with the API's `errorCode`, the invalid `fields`, a `docUrl` and the `requestId`. |
| **Webhooks verified** | `constructEvent` checks the signature and returns the decoded event. |
| **Laravel ready** | A service provider and a facade, discovered automatically. |

## Install

```sh
composer require reeds/reeds-php
```

It needs PHP 8.1 or later and Guzzle 7, which Composer installs for you. Laravel already ships Guzzle.

## Credentials and modes

Get your client id and secret from **Dashboard → Developers**. Each mode has its own pair:

| Mode | Client id | Client secret | What happens |
|---|---|---|---|
| Test | `rci_test_...` | `rcs_test_...` | Nothing real moves and no real money is spent. Use it to build and test. |
| Live | `rci_live_...` | `rcs_live_...` | Real pickups, real charges. Available once Reeds approves your business. |

```php
$reeds = new Reeds\Reeds([
    'client_id' => getenv('REEDS_CLIENT_ID'),
    'client_secret' => getenv('REEDS_CLIENT_SECRET'),
]);

$reeds->mode;      // "test" or "live", from the client id
$reeds->scopes();  // the services your credentials can use, for example ['shipping']
```

`client_id` and `client_secret` default to the `REEDS_CLIENT_ID` and `REEDS_CLIENT_SECRET` environment variables. Keep the secret on your server: never put it in a browser or a mobile app.

## Shipping

The [Shipping API](https://developer.getreeds.co/docs/shipping/overview) books pickups and deliveries: within a state, and between states along the routes Reeds runs.

### Quickstart

```php
<?php

require 'vendor/autoload.php';

$reeds = new Reeds\Reeds();

// 1. Price it.
$quote = $reeds->shipping->quotes->create([
    'pickup' => ['name' => 'Tola Fabrics', 'phone' => '08051234567', 'address' => '23 Allen Avenue', 'area' => 'Ikeja', 'lga' => 'Ikeja', 'state' => 'Lagos'],
    'dropoff' => ['name' => 'Chinedu Eze', 'phone' => '08098765432', 'address' => '14 Admiralty Way', 'area' => 'Lekki Phase 1', 'lga' => 'Eti-Osa', 'state' => 'Lagos'],
    'package' => ['description' => '2 pairs of sneakers', 'quantity' => 1, 'weight_kg' => 1.5],
]);

if (!$quote['available']) {
    // Not an error: Reeds doesn't serve this route. Tell your customer why.
    exit($quote['reason']['message']);
}
echo $quote['fee']['total']; // kobo

// 2. Book it. The wallet is charged and the delivery gets its tracking code.
$delivery = $reeds->shipping->deliveries->create(['quote_id' => $quote['id'], 'reference' => 'ORDER-10045']);

// 3. Follow it with webhooks (see below), or read it.
$latest = $reeds->shipping->deliveries->get($delivery['id']);
echo $latest['status'], ' ', $latest['tracking_url'];
```

Objects are plain arrays with the API's own field names (`quote_id`, `tracking_code`), so your code reads like the [API reference](https://developer.getreeds.co/api), and fields Reeds adds later are simply there. **Amounts are integers in kobo:** `280000` is ₦2,800.

### Coverage and quotes

```php
$coverage = $reeds->shipping->coverage->get(); // the states, zones and inter-state routes Reeds serves
```

A quote holds its price for 24 hours. A route Reeds doesn't serve comes back as `'available' => false` with a `reason` (`ADDRESS_NOT_SERVICEABLE`, `INTERSTATE_NOT_AVAILABLE`, ...), never as an exception. Branch on `$quote['reason']['code']`, not the message.

### Deliveries

| Method | What it does |
|---|---|
| `deliveries->create(['quote_id' => ..., 'reference' => ..., 'ready' => ...])` | Books the delivery a quote priced, and charges it |
| `deliveries->list(['status' => ..., 'reference' => ..., 'ids' => [...], 'created_from' => ..., 'created_to' => ..., 'limit' => ...])` | Deliveries, newest first (see [pagination](#lists-and-pagination)) |
| `deliveries->get($id)` | One delivery |
| `deliveries->update($id, ['reference' => ..., 'pickup_instructions' => ..., 'dropoff_instructions' => ..., 'metadata' => [...]])` | Changes what can change |
| `deliveries->markReady($id)` | The parcel is packed: the pickup is scheduled from now |
| `deliveries->cancel($id, ['reason' => ...])` | Cancels and refunds in full, until the rider sets off |
| `deliveries->book($id)` | Books a priced delivery your team made in the dashboard |
| `deliveries->label($id)` | The shipping label, as a PDF |

**Not packed yet?** Book with `'ready' => false`. The delivery is charged and gets its tracking code, then waits with a `MARK_READY` action:

```php
$delivery = $reeds->shipping->deliveries->create(['quote_id' => $quote['id'], 'ready' => false]);
// ...when the parcel is packed:
$reeds->shipping->deliveries->markReady($delivery['id']); // now "scheduled", with its pickup and delivery times
```

A booked delivery that isn't marked ready within 7 days is cancelled and refunded in full.

### Delivery statuses

| Status | Meaning |
|---|---|
| `requires_action` | Waiting on you: `required_actions` says what (for example `MARK_READY`) |
| `scheduled` | Booked and charged, waiting for its pickup time and a rider |
| `rider_assigned` | A rider has the pickup |
| `picked_up` | Collected from the pickup address |
| `at_hub` | At a Reeds or partner hub |
| `in_transit` | Moving between hubs on an inter-state delivery |
| `out_for_delivery` | With the rider, heading to the receiver |
| `delivered` | Delivered. Final. |
| `failed_attempt` | The rider couldn't deliver. Reeds retries or contacts you. |
| `on_hold` | Paused by Reeds. `hold_reason` says why. |
| `cancelled` | Cancelled. Final. |

Reeds can add statuses: give every `match` or `switch` on `status` a `default` arm.

### Labels

```php
$label = $reeds->shipping->deliveries->label($delivery['id']);
file_put_contents($label->filename ?? 'label.pdf', $label->data); // $label->contentType is "application/pdf"
```

### Wallet and invoices

```php
$wallet = $reeds->shipping->wallet->get();
echo $wallet['balance']; // kobo

// Live mode: start a Paystack top-up (₦50,000) and send the payer to the URL.
// wallet.funded carries the same reference when the money lands.
$topUp = $reeds->shipping->wallet->fund(['amount' => 5_000_000]);
header('Location: ' . $topUp['payment_url']);

foreach ($reeds->shipping->wallet->listTransactions() as $entry) {
    echo $entry['type'], ' ', $entry['amount'], ' ', $entry['description'], PHP_EOL;
}

// Accounts billed monthly:
$invoices = $reeds->shipping->invoices->list(['status' => 'issued']);
$pdf = $reeds->shipping->invoices->pdf($invoices->data[0]['id']);
```

### Test mode and the sandbox

With test credentials, build the whole flow without real money or riders:

```php
$reeds->shipping->sandbox->fundWallet(['amount' => 1_000_000]);                      // ₦10,000 in the test wallet, at once
$reeds->shipping->sandbox->advanceDelivery($delivery['id']);                          // the next status, with its webhook
$reeds->shipping->sandbox->advanceDelivery($delivery['id'], ['status' => 'delivered']); // straight to a status
```

Sandbox calls are refused before anything is sent when the client has live credentials.

## Webhooks

Reeds posts events to your endpoint as things happen. Verify each one before you trust it: pass the **raw** request body, because a body that was decoded and encoded again never matches the signature. The signing secret (`whsec_test_...` or `whsec_live_...`) is next to your endpoint in **Dashboard → Developers**. It isn't your client secret.

**Plain PHP**

```php
use Reeds\Exception\WebhookSignatureException;

$webhooks = new Reeds\Webhooks(); // no credentials needed to verify

try {
    $event = $webhooks->constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_REEDS_SIGNATURE'] ?? null,
        getenv('REEDS_WEBHOOK_SECRET'),
    );
} catch (WebhookSignatureException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

switch ($event['type']) {
    case 'delivery.status_changed':
        $delivery = $event['data']['delivery'];
        error_log("{$delivery['id']}: {$event['data']['previous_status']} -> {$delivery['status']}");
        break;
    case 'wallet.low_balance':
        error_log('Top up: the wallet is at ' . $event['data']['balance']);
        break;
    default:
        break; // Reeds adds event types over time: ignore the ones you don't use
}
http_response_code(200);
```

**Symfony**: `$webhooks->constructEvent($request->getContent(), $request->headers->get('Reeds-Signature'), $secret)`. For Laravel, see [below](#laravel).

| Event | When |
|---|---|
| `delivery.created` | A delivery was created: booked from a quote, or made in the dashboard |
| `delivery.requires_action` | An action was added to `required_actions` |
| `delivery.booked` | The delivery was priced and charged (or added to the month's invoice) |
| `delivery.status_changed` | Any status change |
| `delivery.schedule_changed` | The pickup day or delivery time moved |
| `delivery.expiring` | A delivery will be cancelled soon if nothing happens |
| `delivery.cancelled` | The delivery was cancelled |
| `wallet.funded` | A top-up landed and the wallet was credited |
| `wallet.low_balance` | The balance fell to or below your low-balance threshold |
| `invoice.issued` | A monthly invoice was issued |
| `invoice.paid` | An invoice was paid in full |
| `invoice.overdue` | An invoice passed its due date unpaid |

Good to know:
- **Duplicates:** an event can arrive more than once. Skip `$event['id']`s you've already processed.
- **Order:** events can arrive out of order. Compare `$event['data']['delivery']['updated_at']`.
- **Rotating a secret:** pass both while you rotate, as `constructEvent($body, $header, [$newSecret, $oldSecret])`.
- **Testing your receiver:** `$reeds->webhooks->sendTest(['type' => 'delivery.booked'])` sends a signed test event to your endpoint.

## Laravel

The package's service provider and `Reeds` facade are discovered automatically. Add your credentials to `config/services.php`:

```php
'reeds' => [
    'client_id' => env('REEDS_CLIENT_ID'),
    'client_secret' => env('REEDS_CLIENT_SECRET'),
    'webhook_secret' => env('REEDS_WEBHOOK_SECRET'),
],
```

Then inject the client, or use the facade:

```php
use Reeds\Reeds;

final class ShipOrder
{
    public function __construct(private Reeds $reeds)
    {
    }

    public function handle(string $quoteId, string $orderNumber): array
    {
        return $this->reeds->shipping->deliveries->create(
            ['quote_id' => $quoteId, 'reference' => $orderNumber],
            ['idempotency_key' => "order-{$orderNumber}-delivery"],
        );
    }
}

// Or, anywhere:
$delivery = \Reeds\Laravel\Facades\Reeds::shipping()->deliveries->get('dlv_9Kd3Qx7M');
```

A webhook route, in `routes/api.php` (no CSRF there). `$request->getContent()` is the raw body:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Reeds\Exception\WebhookSignatureException;
use Reeds\Webhooks;

Route::post('/webhooks/reeds', function (Request $request, Webhooks $webhooks) {
    try {
        $event = $webhooks->constructEvent($request->getContent(), $request->header('Reeds-Signature'), config('services.reeds.webhook_secret'));
    } catch (WebhookSignatureException $e) {
        return response($e->getMessage(), 400);
    }
    // dispatch a job for $event['type'] ...
    return response()->noContent();
});
```

## Lists and pagination

Every list returns one page, and `foreach` walks every item across pages:

```php
$page = $reeds->shipping->deliveries->list(['status' => 'delivered', 'limit' => 50]);
$page->data;        // up to 50, newest first
$page->hasMore;     // true when another page follows
$page->nextPage();  // the next page, or null

foreach ($reeds->shipping->deliveries->list(['status' => 'delivered']) as $delivery) {
    // every delivered delivery; pages are fetched as you go
}
```

## Errors

Every failure throws a `Reeds\Exception\ReedsException` subclass. Branch on the class or on `$e->errorCode`, never on the message, which Reeds can reword.

```php
use Reeds\Exception\InvalidRequestException;
use Reeds\Exception\PaymentRequiredException;
use Reeds\Exception\ReedsException;

try {
    $reeds->shipping->deliveries->create(['quote_id' => $quote['id']]);
} catch (PaymentRequiredException $e) {
    // INSUFFICIENT_BALANCE: top up, then retry with the same idempotency_key
} catch (InvalidRequestException $e) {
    print_r($e->fields); // [['field' => 'dropoff.lga', 'message' => 'Enter the LGA.']]
} catch (ReedsException $e) {
    error_log("{$e->errorCode}: {$e->getMessage()} (request {$e->requestId}, see {$e->docUrl})");
    throw $e;
}
```

| Exception | Status | Typical codes |
|---|---|---|
| `AuthenticationException` | 401, or wrong or missing credentials | `TOKEN_EXPIRED`, `invalid_client`, `MISSING_CREDENTIALS` |
| `PermissionDeniedException` | 403 | `INSUFFICIENT_SCOPE`, `IP_NOT_ALLOWED`, `TEST_MODE_ONLY`, `ACCOUNT_ON_HOLD` |
| `NotFoundException` | 404 | `NOT_FOUND` |
| `ConflictException` | 409 | `ACTION_NOT_ALLOWED`, `QUOTE_ALREADY_USED`, `QUOTE_EXPIRED`, `CANCEL_NOT_ALLOWED` |
| `InvalidRequestException` | 400, 422 | `INVALID_REQUEST` (see `fields`), `IDEMPOTENCY_KEY_REQUIRED` |
| `PaymentRequiredException` | 402 | `INSUFFICIENT_BALANCE`, `CREDIT_LIMIT_REACHED` |
| `RateLimitException` | 429 | `RATE_LIMITED` (`$retryAfter` in seconds) |
| `ServiceUnavailableException` | 503 | `SERVICE_UNAVAILABLE` (`$retryAfter` in seconds) |
| `ApiException` | other 5xx | `INTERNAL_ERROR` |
| `ConnectionException`, `ConnectionTimeoutException` | no response | `CONNECTION_FAILED`, `TIMEOUT` |
| `WebhookSignatureException` | a webhook that doesn't verify | `SIGNATURE_MISMATCH`, `TIMESTAMP_OUTSIDE_TOLERANCE`, `RAW_BODY_REQUIRED` |

Every exception has:
- `$status` and `$errorCode` (`getCode()` stays the integer HTTP status);
- `$fields`: what to fix, on validation errors;
- `$docUrl`: the code's page on the developer hub;
- `$requestId`: quote it to support;
- `$raw`: the API's error object, including fields some codes add, such as `delivery_id` on `QUOTE_ALREADY_USED`.

## Retries, idempotency and timeouts

| Retried (up to `max_retries`, default 2) | Never retried |
|---|---|
| No response (network error, timeout) | `400`, `401`*, `402`, `403`, `404`, `409`, `422` |
| `429`, `502`, `503`, `504`, waiting for `Retry-After` (at most 30 s) | |
| `500` on a `GET` | |
| `409 REQUEST_IN_PROGRESS` (the same key still running) | |

\* An expired token is refreshed once and the request sent again, automatically.

Waits back off from about half a second, up to 8 seconds. Every `POST` carries an `Idempotency-Key`, and a retry sends the same one, so Reeds acts on it once. To make your own retries safe too (after a crash, or a queue retrying a job), pass a key tied to your order:

```php
$reeds->shipping->deliveries->create(
    ['quote_id' => $quote['id'], 'reference' => 'ORDER-10045'],
    ['idempotency_key' => 'order-10045-delivery'],
);
```

The last argument of every method sets options for that request only: `idempotency_key`, `timeout` (seconds), `max_retries` and `headers`.

```php
$reeds->shipping->deliveries->list(['limit' => 10], ['timeout' => 10, 'max_retries' => 0]);
```

## Types and static analysis

Objects are arrays, and the library ships their shapes for PHPStan and Psalm in `Reeds\Shipping\Types`, generated from the API's OpenAPI spec. Your IDE and your analyser know every field. Import a shape into your own code:

```php
/**
 * @phpstan-import-type Delivery from \Reeds\Shipping\Types
 */
final class Fulfilment
{
    /** @param Delivery $delivery */
    public function isDone(array $delivery): bool
    {
        return in_array($delivery['status'], ['delivered', 'cancelled'], true);
    }
}
```

Statuses and other enums are typed as plain strings, so a value Reeds adds later never breaks your analysis.

## Configuration

```php
new Reeds\Reeds([
    'client_id' => 'rci_test_...',          // default: REEDS_CLIENT_ID
    'client_secret' => 'rcs_test_...',      // default: REEDS_CLIENT_SECRET
    'base_url' => 'https://api.getreeds.co', // the default
    'timeout' => 60,                         // seconds per attempt
    'max_retries' => 2,
    'http_client' => new GuzzleHttp\Client(['proxy' => 'http://proxy.internal:8080']), // any Guzzle client
    'default_headers' => ['X-Your-Header' => 'value'],
]);
```

## Versioning

The library follows [semantic versioning](https://semver.org). Until 1.0.0, a minor version may change something you use: read the [changelog](CHANGELOG.md) before upgrading.

Within an API version (`v1`), Reeds adds fields, statuses and event types without notice. The library keeps them in the arrays it returns.

## Support

- **Docs and guides:** [developer.getreeds.co](https://developer.getreeds.co).
- **Help with a call:** email support@getreeds.co with the `requestId` from the exception (or the `X-Request-Id` header). Every call is also listed in Dashboard → Developers → API requests for 30 days.
- **A bug in the library:** [open an issue](https://github.com/SendX-hq/reeds-php/issues).
- **A security problem:** email support@getreeds.co rather than opening an issue.

## License

[MIT](LICENSE) © Reeds Merchants Technologies Ltd.
