<?php

declare(strict_types=1);

namespace Reeds\Tests;

use PHPUnit\Framework\TestCase;
use Reeds\Reeds;
use Reeds\Shipping\Events;
use Reeds\Version;
use Symfony\Component\Yaml\Yaml;

/**
 * The library against its specs: every operation has a method, every event type
 * is known, and nothing points at an operation the spec doesn't have. When the
 * sync-spec workflow brings in a new operation, this fails until the method exists.
 */
final class CoverageTest extends TestCase
{
    /** operationId => [the object, the method]; the token endpoint is the core's. */
    private static function methods(): array
    {
        $reeds = new Reeds(['client_id' => 'rci_test_x', 'client_secret' => 'rcs_test_x']);
        $s = $reeds->shipping;
        return [
            'createAccessToken' => 'core',
            'getCoverage' => [$s->coverage, 'get'],
            'createQuote' => [$s->quotes, 'create'],
            'createDelivery' => [$s->deliveries, 'create'],
            'listDeliveries' => [$s->deliveries, 'list'],
            'getDelivery' => [$s->deliveries, 'get'],
            'updateDelivery' => [$s->deliveries, 'update'],
            'bookDelivery' => [$s->deliveries, 'book'],
            'markDeliveryReady' => [$s->deliveries, 'markReady'],
            'cancelDelivery' => [$s->deliveries, 'cancel'],
            'getDeliveryLabel' => [$s->deliveries, 'label'],
            'getWallet' => [$s->wallet, 'get'],
            'fundWallet' => [$s->wallet, 'fund'],
            'listWalletTransactions' => [$s->wallet, 'listTransactions'],
            'listInvoices' => [$s->invoices, 'list'],
            'getInvoice' => [$s->invoices, 'get'],
            'getInvoicePdf' => [$s->invoices, 'pdf'],
            'sendTestWebhook' => [$reeds->webhooks, 'sendTest'],
            'sandboxFundWallet' => [$s->sandbox, 'fundWallet'],
            'sandboxAdvanceDelivery' => [$s->sandbox, 'advanceDelivery'],
        ];
    }

    /** @return array<string, mixed> */
    private static function spec(string $file): array
    {
        return Yaml::parseFile(__DIR__ . "/../spec/{$file}");
    }

    public function testEveryShippingOperationHasAMethodAndEveryMethodAnOperation(): void
    {
        $ids = [];
        foreach (self::spec('shipping-v1.yaml')['paths'] as $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $ids[] = $operation['operationId'];
                }
            }
        }
        $methods = self::methods();
        $mapped = array_keys($methods);
        sort($ids);
        sort($mapped);
        self::assertSame($ids, $mapped);
        foreach ($methods as $operationId => $method) {
            if ($method !== 'core') {
                self::assertTrue(method_exists($method[0], $method[1]), $operationId);
            }
        }
    }

    public function testEveryShippingWebhookEventTypeIsKnown(): void
    {
        $types = Events::TYPES;
        $spec = array_keys(self::spec('shipping-v1.yaml')['webhooks'] ?? []);
        sort($types);
        sort($spec);
        self::assertSame($spec, $types);
    }

    public function testTheVersionIsTheChangelogsLatest(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        self::assertMatchesRegularExpression('/^## ' . preg_quote(Version::VERSION, '/') . '\b/m', $changelog);
        preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $first);
        self::assertSame(Version::VERSION, $first[1] ?? null, 'The newest CHANGELOG entry is the version in src/Version.php.');
    }
}
