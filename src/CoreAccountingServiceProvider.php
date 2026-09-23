<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting;

use Ctpl\CoreAccounting\Http\Connection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class CoreAccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-accounting.php', 'core-accounting');

        $this->app->singleton(Connection::class, function ($app) {
            $config = $app['config']->get('core-accounting');
            $config['name'] = 'default';

            $this->assertConfigured($config);

            return new Connection($app->make(HttpFactory::class), $config);
        });

        $this->app->singleton(CoreAccounting::class, fn ($app) => new CoreAccounting($app->make(Connection::class)));

        $this->app->alias(CoreAccounting::class, 'core-accounting');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/core-accounting.php' => config_path('core-accounting.php'),
            ], 'core-accounting-config');
        }
    }

    /**
     * Fail at resolution rather than at the first request.
     *
     * A missing credential otherwise surfaces as a 401 from the ledger on
     * whatever call happened to run first - which reads as "the ledger is
     * rejecting us" rather than "this application was never configured", and
     * sends somebody to check the credentials on the SERVER rather than the
     * blank line in their own .env.
     *
     * @param  array<string,mixed>  $config
     */
    protected function assertConfigured(array $config): void
    {
        $missing = [];

        /*
         * `base_url` is deliberately absent: it has a compiled-in default, so a
         * blank one is not a misconfiguration. The three credentials have no
         * default and cannot have one.
         */
        foreach (['app_id' => 'CORE_ACCOUNTING_APP_ID',
            'key' => 'CORE_ACCOUNTING_KEY', 'secret' => 'CORE_ACCOUNTING_SECRET'] as $key => $env) {
            if (($config[$key] ?? null) === null || $config[$key] === '') {
                $missing[] = $env;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Core Accounting is not configured: %s %s not set. An administrator issues these once, '
                .'on the Cloud applications screen - the secret is displayed only at that moment and '
                .'is stored as a hash, so a lost one means a new key rather than a lookup.',
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }
    }
}
