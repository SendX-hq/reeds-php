<?php

declare(strict_types=1);

namespace Reeds\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The app's Reeds client:
 *
 *     Reeds::shipping()->deliveries->create(['quote_id' => $quoteId]);
 *     Reeds::webhooks()->constructEvent($request->getContent(), $request->header('Reeds-Signature'), $secret);
 *
 * @see \Reeds\Reeds
 * @method static \Reeds\Shipping\Shipping shipping()
 * @method static \Reeds\Webhooks webhooks()
 * @method static list<string> scopes()
 */
final class Reeds extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Reeds\Reeds::class;
    }
}
