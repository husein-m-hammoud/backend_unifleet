<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The public auth endpoints are the only unauthenticated write surface on an
 * internet-facing box, so the limits guarding them are worth a test rather than
 * an assumption.
 *
 * These deliberately send an INVALID body. Validation fails inside the
 * controller, which runs after the throttle middleware, so each call still
 * consumes a slot while never reaching the database — which matters because the
 * schema is PostGIS-specific and will not migrate on the sqlite test
 * connection. A 422 therefore means "the throttle let it through" and a 429
 * means "the throttle stopped it", which is exactly what is under test.
 */
class AuthRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The array cache store persists for the whole process, so limits would
        // otherwise leak between test methods.
        foreach (['login', 'password', 'api'] as $limiter) {
            RateLimiter::clear($limiter);
        }
    }

    public function test_login_is_throttled_after_five_attempts_from_one_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [])
                ->assertStatus(422, "attempt {$i} should still be allowed through to validation");
        }

        $this->postJson('/api/auth/login', [])
            ->assertStatus(429);
    }

    public function test_forgot_password_is_throttled_after_three_attempts(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/forgot-password', [])
                ->assertStatus(422, "attempt {$i} should still be allowed through to validation");
        }

        $this->postJson('/api/auth/forgot-password', [])
            ->assertStatus(429);
    }

    public function test_reset_password_shares_the_password_limiter(): void
    {
        // Three on forgot-password must exhaust the budget for reset-password
        // too, so an attacker cannot get 3 free attempts on each route.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/forgot-password', [])->assertStatus(422);
        }

        $this->postJson('/api/auth/reset-password', [])
            ->assertStatus(429);
    }

    public function test_health_endpoint_is_not_blocked_by_the_auth_limits(): void
    {
        // The uptime monitor must keep working while someone is being throttled
        // on login, otherwise an attack looks like an outage.
        for ($i = 1; $i <= 6; $i++) {
            $this->postJson('/api/auth/login', []);
        }

        // Only the status matters here: /api/health may legitimately return 200
        // or 503 depending on the checks, but it must never return 429.
        $this->assertNotSame(429, $this->getJson('/api/health')->status());
    }
}
