<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        $this->registerRateLimiters();
    }

    /**
     * Rate limits for the API.
     *
     * The box is internet-facing, so the unauthenticated endpoints are the ones
     * that matter: without these, /auth/login is an unlimited password-guessing
     * oracle and /auth/forgot-password is an unlimited outbound email trigger.
     *
     * Authenticated traffic is keyed on the USER, never the IP. The dashboard
     * polls continuously (notifications every 20s, several widgets every 30-60s)
     * and a whole office sits behind one NAT address, so an IP-keyed limit would
     * throttle the product itself long before it throttled an attacker.
     */
    private function registerRateLimiters(): void
    {
        // Generous ceiling for signed-in users: ~15 req/min is normal for an open
        // dashboard tab, so 300 leaves room for several tabs while still stopping
        // a runaway script or a stolen token being used to scrape the fleet.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));

        /*
         * Login: two independent limits, because they stop different attacks.
         *   - per IP      -> one host spraying many accounts
         *   - per account -> a distributed attack on one known username
         * Keyed on the lowercased username so casing cannot be used to get a
         * fresh bucket.
         */
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login-ip:'.$request->ip()),
            Limit::perMinute(10)->by('login-user:'.Str::lower((string) $request->input('username'))),
        ]);

        /*
         * Password reset request + submission. Deliberately tighter than login:
         * each accepted request sends a real email, so an open endpoint is both a
         * user-enumeration probe and a way to spam a third party from our domain
         * (which is how a new sending domain gets blacklisted). An hourly cap sits
         * under the per-minute one so slow, patient abuse is caught too.
         */
        RateLimiter::for('password', fn (Request $request) => [
            Limit::perMinute(3)->by('pw-ip:'.$request->ip()),
            Limit::perHour(15)->by('pw-ip-hour:'.$request->ip()),
        ]);
    }
}
