<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        RateLimiter::for('stock-adjustment', function ($request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Unauthenticated breakdown QR-scan flow — keyed by IP since there's
        // no user to key on, generous enough for a real shop-floor burst of
        // scans but not for scripted abuse.
        RateLimiter::for('breakdown-public', function ($request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
