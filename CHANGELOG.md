# Changelog

## 0.1.0 (unreleased)

The first release, for the Shipping API. Install with `composer require reeds/reeds-php`.

- `$reeds->shipping`: coverage, quotes, deliveries (create, list, get, update, book, mark ready, cancel, label), the wallet (get, fund, transactions), invoices (list, get, PDF) and the sandbox.
- `$reeds->webhooks`: signature verification (both secrets while you rotate one), decoded events, test events.
- Access tokens fetched and refreshed automatically; an `Idempotency-Key` on every `POST`, reused by retries; safe retries with `Retry-After`; cursor pagination with `foreach`; one exception class per kind of failure.
- PHPStan / Psalm array shapes for every object, generated from the Shipping API's OpenAPI spec.
- A Laravel service provider and `Reeds` facade, discovered automatically.
