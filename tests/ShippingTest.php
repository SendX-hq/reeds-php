<?php

declare(strict_types=1);

namespace Reeds\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Reeds\Core\Page;
use Reeds\Exception\PermissionDeniedException;
use Reeds\Tests\Support\FakeApi;

final class ShippingTest extends TestCase
{
    private const PICKUP = ['name' => 'Tola Fabrics', 'phone' => '08051234567', 'address' => '23 Allen Avenue', 'lga' => 'Ikeja', 'state' => 'Lagos'];
    private const DROPOFF = ['name' => 'Chinedu Eze', 'phone' => '08098765432', 'address' => '14 Admiralty Way', 'lga' => 'Eti-Osa', 'state' => 'Lagos'];
    private const SESSION = '3f2c8d1e-7b4a-4c55-9e0d-2a6b1f9c8e70';
    private const PLACE = [
        'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        'name' => '23 Allen Avenue',
        'formatted_address' => '23 Allen Ave, Allen, Ikeja 101233, Lagos, Nigeria',
        'area' => 'Ikeja',
        'lga' => 'Ikeja',
        'state' => 'Lagos',
        'country' => 'NG',
        'latitude' => 6.6018,
        'longitude' => 3.3515,
        'precise' => true,
    ];

    public function testSendsEachCallToItsPathWithItsMethodBodyAndQuery(): void
    {
        $api = new FakeApi(static function (RequestInterface $request): Response {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/label') || str_ends_with($path, '/pdf')) {
                return new Response(200, ['Content-Type' => 'application/pdf'], '%PDF');
            }
            if ($request->getMethod() === 'GET' && preg_match('#/(deliveries|transactions|invoices)$#', $path) === 1) {
                return FakeApi::json(200, ['status' => 'success', 'data' => [], 'has_more' => false]);
            }
            return FakeApi::success(['id' => 'x']);
        });
        $reeds = $api->client();
        $s = $reeds->shipping;
        $s->coverage->get();
        $s->quotes->create(['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF, 'package' => ['weight_kg' => 2]]);
        $s->deliveries->create(['quote_id' => 'qt_1', 'reference' => 'ORDER-1', 'ready' => false]);
        $s->deliveries->list(['status' => 'scheduled', 'ids' => ['dlv_1', 'dlv_2'], 'limit' => 10]);
        $s->deliveries->get('dlv_1');
        $s->deliveries->update('dlv_1', ['reference' => 'ORDER-2']);
        $s->deliveries->book('dlv_1');
        $s->deliveries->markReady('dlv_1');
        $s->deliveries->cancel('dlv_1', ['reason' => 'Customer cancelled']);
        $s->deliveries->label('dlv_1');
        $s->addresses->autocomplete(['query' => '23 Allen Avenue', 'session_token' => self::SESSION]);
        $s->addresses->details(['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', 'session_token' => self::SESSION]);
        $s->addresses->reverse(['latitude' => 6.6018, 'longitude' => 3.3515]);
        $s->wallet->get();
        $s->wallet->fund(['amount' => 500000]);
        $s->wallet->listTransactions(['limit' => 5]);
        $s->invoices->list(['status' => 'issued']);
        $s->invoices->get('inv_1');
        $s->invoices->pdf('inv_1');
        $s->sandbox->fundWallet(['amount' => 1000000]);
        $s->sandbox->advanceDelivery('dlv_1', ['status' => 'delivered']);
        $reeds->webhooks->sendTest(['type' => 'delivery.booked']);

        $seen = array_map(static function (RequestInterface $call): string {
            $uri = $call->getUri();
            $body = (string) $call->getBody();
            return "{$call->getMethod()} {$uri->getPath()}" . ($uri->getQuery() !== '' ? "?{$uri->getQuery()}" : '') . ($body !== '' ? " {$body}" : '');
        }, $api->apiCalls());
        self::assertSame([
            'GET /shipping/v1/coverage',
            'POST /shipping/v1/quotes ' . json_encode(['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF, 'package' => ['weight_kg' => 2]]),
            'POST /shipping/v1/deliveries {"quote_id":"qt_1","reference":"ORDER-1","ready":false}',
            'GET /shipping/v1/deliveries?status=scheduled&ids=dlv_1%2Cdlv_2&limit=10',
            'GET /shipping/v1/deliveries/dlv_1',
            'PATCH /shipping/v1/deliveries/dlv_1 {"reference":"ORDER-2"}',
            'POST /shipping/v1/deliveries/dlv_1/book',
            'POST /shipping/v1/deliveries/dlv_1/ready',
            'POST /shipping/v1/deliveries/dlv_1/cancel {"reason":"Customer cancelled"}',
            'GET /shipping/v1/deliveries/dlv_1/label',
            'GET /shipping/v1/addresses/autocomplete?query=23%20Allen%20Avenue&session_token=' . self::SESSION,
            'GET /shipping/v1/addresses/details?place_id=ChIJN1t_tDeuEmsRUsoyG83frY4&session_token=' . self::SESSION,
            'GET /shipping/v1/addresses/reverse?latitude=6.6018&longitude=3.3515',
            'GET /shipping/v1/wallet',
            'POST /shipping/v1/wallet/fund {"amount":500000}',
            'GET /shipping/v1/wallet/transactions?limit=5',
            'GET /shipping/v1/invoices?status=issued',
            'GET /shipping/v1/invoices/inv_1',
            'GET /shipping/v1/invoices/inv_1/pdf',
            'POST /shipping/v1/sandbox/wallet/fund {"amount":1000000}',
            'POST /shipping/v1/sandbox/deliveries/dlv_1/advance {"status":"delivered"}',
            'POST /shipping/v1/webhooks/test {"type":"delivery.booked"}',
        ], $seen);
        $label = array_values(array_filter($api->apiCalls(), static fn ($c) => str_ends_with($c->getUri()->getPath(), '/label')))[0];
        self::assertSame('application/pdf', $label->getHeaderLine('Accept'));
    }

    public function testAnEmptyBodySendsAnEmptyJsonObjectNotAList(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(['id' => 'x']));
        $api->client()->shipping->deliveries->cancel('dlv_1');
        self::assertSame('{}', (string) $api->apiCalls()[0]->getBody());
    }

    public function testEscapesIdsInThePath(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]));
        $api->client()->shipping->deliveries->get('dlv_1/../wallet');
        self::assertSame('/shipping/v1/deliveries/dlv_1%2F..%2Fwallet', $api->apiCalls()[0]->getUri()->getPath());
    }

    public function testARouteReedsDoesntServeIsAvailableFalseNotAnError(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(['available' => false, 'reason' => ['code' => 'INTERSTATE_NOT_AVAILABLE', 'message' => "Reeds doesn't deliver from Lagos to Kano."]]));
        $quote = $api->client()->shipping->quotes->create(['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF]);
        self::assertFalse($quote['available']);
    }

    public function testAddressSearchReturnsTheSuggestionsAndThePlace(): void
    {
        $suggestions = ['suggestions' => [
            ['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', 'main_text' => '23 Allen Avenue', 'secondary_text' => 'Ikeja, Lagos, Nigeria'],
            ['place_id' => 'ChIJ8TQy0JKLOxARmZ9bJc2sVkA', 'main_text' => 'Allen Avenue Roundabout', 'secondary_text' => 'Ikeja, Lagos, Nigeria'],
        ]];
        $api = new FakeApi(static fn (RequestInterface $request): Response => str_ends_with($request->getUri()->getPath(), '/autocomplete')
            ? FakeApi::success($suggestions)
            : FakeApi::success(self::PLACE));
        $addresses = $api->client()->shipping->addresses;

        self::assertSame($suggestions, $addresses->autocomplete(['query' => 'Allen', 'session_token' => self::SESSION]));
        self::assertSame(self::PLACE, $addresses->details(['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4']));
        self::assertSame(self::PLACE, $addresses->reverse(['latitude' => 6.6018, 'longitude' => 3.3515]));

        $calls = $api->apiCalls();
        // Without a session token the place is looked up on its own: no empty token is sent.
        self::assertSame('place_id=ChIJN1t_tDeuEmsRUsoyG83frY4', $calls[1]->getUri()->getQuery());
        foreach ($calls as $call) {
            self::assertSame('GET', $call->getMethod());
            self::assertSame('', (string) $call->getBody());
            self::assertFalse($call->hasHeader('Idempotency-Key'));
        }
    }

    public function testAPinIsSentAsPlainDecimalsWhateverTheLocale(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success(self::PLACE));
        $locale = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');
        try {
            $addresses = $api->client()->shipping->addresses;
            $addresses->reverse(['latitude' => 0.00001, 'longitude' => -0.000003]);
            $addresses->reverse(['latitude' => 9.0765, 'longitude' => 7.3986]);
            $addresses->reverse(['latitude' => 6, 'longitude' => 3]);
        } finally {
            setlocale(LC_NUMERIC, $locale === false ? 'C' : $locale);
        }
        self::assertSame([
            'latitude=0.00001&longitude=-0.000003',
            'latitude=9.0765&longitude=7.3986',
            'latitude=6&longitude=3',
        ], array_map(static fn ($c) => $c->getUri()->getQuery(), $api->apiCalls()));
    }

    public function testASearchPastTheAllowanceOrSwitchedOffIsPermissionDenied(): void
    {
        foreach (['ADDRESS_SEARCH_LIMIT_REACHED', 'ADDRESS_SEARCH_DISABLED'] as $code) {
            $api = new FakeApi(static fn () => FakeApi::failure(403, $code, 'Contact Reeds support.'));
            try {
                $api->client()->shipping->addresses->autocomplete(['query' => 'Allen', 'session_token' => self::SESSION]);
                self::fail("Expected {$code}.");
            } catch (PermissionDeniedException $error) {
                self::assertSame($code, $error->errorCode);
                self::assertSame(403, $error->getCode());
                self::assertSame("https://developer.getreeds.co/errors/{$code}", $error->docUrl);
            }
            self::assertCount(1, $api->apiCalls(), 'A 403 is not retried.');
        }
    }

    /** @param list<list<string>> $pages */
    private static function pages(array $pages): FakeApi
    {
        return new FakeApi(static function (RequestInterface $request) use ($pages): Response {
            parse_str($request->getUri()->getQuery(), $query);
            $after = $query['starting_after'] ?? null;
            $index = 0;
            if (is_string($after)) {
                foreach ($pages as $i => $page) {
                    if ($page !== [] && end($page) === $after) {
                        $index = $i + 1;
                    }
                }
            }
            $page = $pages[$index] ?? [];
            return FakeApi::json(200, ['status' => 'success', 'data' => array_map(static fn (string $id) => FakeApi::delivery($id), $page), 'has_more' => $index < count($pages) - 1]);
        });
    }

    public function testAListIsOnePageWithHasMoreAndNextPage(): void
    {
        $api = self::pages([['dlv_3', 'dlv_2'], ['dlv_1']]);
        $page = $api->client()->shipping->deliveries->list(['limit' => 2]);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame(['dlv_3', 'dlv_2'], array_column($page->data, 'id'));
        self::assertTrue($page->hasMore);
        $next = $page->nextPage();
        self::assertNotNull($next);
        self::assertSame(['dlv_1'], array_column($next->data, 'id'));
        self::assertFalse($next->hasMore);
        self::assertNull($next->nextPage());
        self::assertSame('limit=2&starting_after=dlv_2', $api->apiCalls()[1]->getUri()->getQuery());
    }

    public function testForeachWalksEveryPageKeepingTheFilters(): void
    {
        $api = self::pages([['dlv_5', 'dlv_4'], ['dlv_3', 'dlv_2'], ['dlv_1']]);
        $ids = [];
        foreach ($api->client()->shipping->deliveries->list(['status' => 'delivered', 'limit' => 2]) as $delivery) {
            $ids[] = $delivery['id'];
        }
        self::assertSame(['dlv_5', 'dlv_4', 'dlv_3', 'dlv_2', 'dlv_1'], $ids);
        self::assertSame([
            'status=delivered&limit=2',
            'status=delivered&limit=2&starting_after=dlv_4',
            'status=delivered&limit=2&starting_after=dlv_2',
        ], array_map(static fn ($c) => $c->getUri()->getQuery(), $api->apiCalls()));
    }

    public function testAnEmptyListEndsAtOnce(): void
    {
        $api = self::pages([[]]);
        $items = iterator_to_array($api->client()->shipping->wallet->listTransactions(), false);
        self::assertSame([], $items);
        self::assertCount(1, $api->apiCalls());
    }

    public function testReturnsALabelsBytesTypeAndFilename(): void
    {
        $api = new FakeApi(static fn () => new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="reeds-label-HQ3W5F2.pdf"'], '%PDF-1.7 label'));
        $file = $api->client()->shipping->deliveries->label('dlv_1');
        self::assertSame('reeds-label-HQ3W5F2.pdf', $file->filename);
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('%PDF-1.7 label', $file->data);
    }

    public function testTheSandboxRefusesLiveCredentialsBeforeSendingAnything(): void
    {
        $api = new FakeApi(static fn () => FakeApi::success([]));
        $reeds = $api->client(['client_id' => 'rci_live_abc', 'client_secret' => 'rcs_live_xyz']);
        foreach ([static fn () => $reeds->shipping->sandbox->fundWallet(['amount' => 1000]), static fn () => $reeds->shipping->sandbox->advanceDelivery('dlv_1')] as $call) {
            try {
                $call();
                self::fail('Expected TEST_MODE_ONLY.');
            } catch (PermissionDeniedException $error) {
                self::assertSame('TEST_MODE_ONLY', $error->errorCode);
            }
        }
        self::assertCount(0, $api->calls);
    }
}
