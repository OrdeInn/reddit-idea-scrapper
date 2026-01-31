<?php

namespace App\Providers;

use App\Services\Reddit\RedditService;
use Illuminate\Support\ServiceProvider;

class RedditServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(RedditService::class, function ($app) {
            return new RedditService();
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
