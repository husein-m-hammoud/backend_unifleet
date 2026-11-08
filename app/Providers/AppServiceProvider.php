<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        $this->trustUpstreamProxy();
    }

    /**
     * Trust an upstream TLS terminator, when there is one.
     *
     * Set TRUSTED_PROXIES only when TLS terminates IN FRONT of this server (an
     * Alibaba SLB/ALB, a CDN, or a WAF). Such a proxy speaks plaintext HTTP to
     * us and reports the real scheme in X-Forwarded-Proto; until it is trusted,
     * Laravel reads every HTTPS request as plaintext, emits http:// URLs and
     * redirect-loops against the proxy's own HTTPS redirect.
     *
     * Leave it EMPTY when nginx on this box holds the certificate. Trusting a
     * proxy that is not there lets any client forge X-Forwarded-* and spoof its
     * own scheme and source IP - and the API rate limiter keys unauthenticated
     * traffic on the IP, so a spoofable IP is a bypassable login throttle.
     *
     * TrustProxies is already in the global middleware stack; it just trusts
     * nothing until the static below is set. This lives here rather than in
     * bootstrap/app.php because the withMiddleware() closure runs before the
     * framework bootstraps config, where config() is not yet resolvable.
     */
    private function trustUpstreamProxy(): void
    {
        $proxies = config('app.trusted_proxies');

        if (blank($proxies)) {
            return;
        }

        TrustProxies::at(
            $proxies === '*' ? '*' : array_values(array_filter(
                array_map('trim', explode(',', $proxies))
            ))
        );
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
        /*
         * Self-service password change (authenticated). The current-password
         * check makes this a password-guessing oracle for anyone holding a
         * stolen token, so it is capped far below the 300/min the rest of the
         * authenticated API allows. Keyed on the USER, not the IP: a whole
         * office behind one NAT address must not share a bucket for something
         * each of them does legitimately. The hourly cap catches slow guessing.
         */
        RateLimiter::for('password-change', fn (Request $request) => [
            Limit::perMinute(5)->by('pwc:'.($request->user()?->id ?: $request->ip())),
            Limit::perHour(20)->by('pwc-hour:'.($request->user()?->id ?: $request->ip())),
        ]);

        RateLimiter::for('password', fn (Request $request) => [
            Limit::perMinute(3)->by('pw-ip:'.$request->ip()),
            Limit::perHour(15)->by('pw-ip-hour:'.$request->ip()),
        ]);
    }
}
