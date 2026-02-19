<?php

namespace App\Providers;

use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Horizon::auth() is set here (after parent::boot()) to ensure it is
     * the final auth callback and cannot be overwritten by the base provider.
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request) {
            if (app()->environment('local')) {
                return true;
            }

            $allowedIps = env('HORIZON_ALLOWED_IPS');

            if ($allowedIps) {
                $ips = array_filter(array_map('trim', explode(',', $allowedIps)));

                return in_array($request->ip(), $ips, true);
            }

            return false;
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#bots');
    }

    /**
     * Register the Horizon gate.
     *
     * Auth is handled directly in boot() via Horizon::auth() — no Gate
     * ability is needed since the project has no User model.
     */
    protected function gate(): void
    {
        // No-op: authorization handled in boot() via Horizon::auth()
    }
}
