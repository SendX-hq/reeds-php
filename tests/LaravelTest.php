<?php

declare(strict_types=1);

namespace Reeds\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Reeds\Laravel\Facades\Reeds as ReedsFacade;
use Reeds\Laravel\ReedsServiceProvider;
use Reeds\Reeds;
use Reeds\Shipping\Shipping;
use Reeds\Webhooks;

final class LaravelTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        $this->app = new Container();
        $this->app->instance('config', new Repository(['services' => ['reeds' => [
            'client_id' => 'rci_live_fromconfig',
            'client_secret' => 'rcs_live_fromconfig',
            'base_url' => '',
        ]]]));
        (new ReedsServiceProvider($this->app))->register();
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    public function testRegistersOneClientFromConfigServicesReeds(): void
    {
        $reeds = $this->app->make(Reeds::class);
        self::assertInstanceOf(Reeds::class, $reeds);
        self::assertSame('live', $reeds->mode);
        self::assertSame($reeds, $this->app->make(Reeds::class));
        self::assertSame($reeds, $this->app->make('reeds'));
        self::assertSame($reeds->webhooks, $this->app->make(Webhooks::class));
    }

    public function testTheFacadeReachesTheClientsServices(): void
    {
        self::assertInstanceOf(Shipping::class, ReedsFacade::shipping());
        self::assertInstanceOf(Webhooks::class, ReedsFacade::webhooks());
        self::assertSame($this->app->make(Reeds::class), ReedsFacade::getFacadeRoot());
    }
}
