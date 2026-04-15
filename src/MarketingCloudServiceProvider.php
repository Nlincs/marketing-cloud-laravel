<?php

namespace YourVendor\MarketingCloud;

use Illuminate\Support\ServiceProvider;

class MarketingCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/marketingcloud.php',
            'marketingcloud'
        );

        $this->app->singleton(MarketingCloudService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/marketingcloud.php' => config_path('marketingcloud.php'),
        ], 'marketingcloud-config');
    }
}