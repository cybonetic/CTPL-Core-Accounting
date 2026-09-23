<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests;

use Ctpl\CoreAccounting\CoreAccountingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CoreAccountingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('core-accounting', array_merge(
            require __DIR__.'/../config/core-accounting.php',
            [
                'base_url' => 'https://ledger.test',
                'app_id' => 'CTPL-TEST-APP',
                'key' => 'ak_test',
                'secret' => 'sk_test',
                'company_id' => 1,
                'retry' => ['times' => 2, 'base_delay_ms' => 1],
                'defer_writes' => false,
            ],
        ));
    }
}
