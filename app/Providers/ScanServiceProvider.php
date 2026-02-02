<?php

namespace App\Providers;

use App\Services\ScanService;
use Illuminate\Support\ServiceProvider;

class ScanServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ScanService::class, function ($app) {
            return new ScanService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
