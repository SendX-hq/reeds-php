<?php

declare(strict_types=1);

namespace Reeds\Laravel;

use Illuminate\Support\ServiceProvider;
use Reeds\Reeds;
use Reeds\Webhooks;

/**
 * Registers one Reeds client for the app (auto-discovered). It reads
 * `config('services.reeds')`:
 *
 *     'reeds' => [
 *         'client_id' => env('REEDS_CLIENT_ID'),
 *         'client_secret' => env('REEDS_CLIENT_SECRET'),
 *         'webhook_secret' => env('REEDS_WEBHOOK_SECRET'),
 *     ],
 *
 * and falls back to the REEDS_CLIENT_ID / REEDS_CLIENT_SECRET environment
 * variables. Resolve it with `app(Reeds::class)`, type-hint it, or use the
 * `Reeds` facade.
 */
final class ReedsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Reeds::class, function ($app): Reeds {
            $config = $app->bound('config') ? (array) $app->make('config')->get('services.reeds', []) : [];
            $options = array_filter([
                'client_id' => $config['client_id'] ?? null,
                'client_secret' => $config['client_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'timeout' => $config['timeout'] ?? null,
                'max_retries' => $config['max_retries'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');
            return new Reeds($options);
        });
        $this->app->alias(Reeds::class, 'reeds');
        $this->app->bind(Webhooks::class, fn ($app): Webhooks => $app->make(Reeds::class)->webhooks);
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Reeds::class, 'reeds', Webhooks::class];
    }
}
