<?php

namespace Nlincs\MarketingCloudLaravel\Tests;

use Nlincs\MarketingCloudLaravel\MarketingCloudServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MarketingCloudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('marketingcloud', [
            'org_id' => 'test-org',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'list_id' => '1130',
            'data_extensions' => [
                'newsletter' => [
                    'key' => 'DE-KEY',
                    'primary_key' => 'Subscriber Key',
                ],
            ],
        ]);
    }
}
