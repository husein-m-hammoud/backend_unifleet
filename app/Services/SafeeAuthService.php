<?php

namespace App\Services;

use App\Models\ProviderApiFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Service-account (password grant) auth against a Safee Tracking provider.
 *
 * DSCO was one Safee deployment; Alrakeen and (later) saudiX are others. Each
 * provider has its own server_uri / realm / credentials in
 * config('services.safee.providers.*'). The access token is cached per provider.
 */
class SafeeAuthService
{
    private string $provider;

    /** @var array{server_uri:string,realm:string,client_id:string,client_secret:string,username:string,password:string} */
    private array $cfg;

    public function __construct(?string $provider = null)
    {
        $this->provider = $provider ?? config('services.safee.default');
        $cfg = config("services.safee.providers.{$this->provider}");

        if (! $cfg) {
            throw new \InvalidArgumentException("Unknown Safee provider [{$this->provider}].");
        }

        $this->cfg = $cfg;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** @return array The raw provider config (used by the per-user auth service). */
    public function config(): array
    {
        return $this->cfg;
    }

    public function baseUrl(): string
    {
        return rtrim($this->cfg['server_uri'], '/');
    }

    public function authUrl(): string
    {
        return $this->baseUrl() . "/auth/realms/{$this->cfg['realm']}/protocol/openid-connect/token";
    }

    public function getToken(): string
    {
        return Cache::remember($this->cacheKey(), $this->ttl(), fn () => $this->fetchToken());
    }

    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return "safee_access_token_{$this->provider}";
    }

    private function fetchToken(): string
    {
        // Fail fast if the provider is unreachable: cURL's default connect
        // timeout is ~300s, so with no limit here one dead provider hangs every
        // poll for 5 minutes (this is what wedged the poller during the Sep 2026
        // Alrakeen outage). Cap connect at 10s and the whole exchange at 20s.
        // Every failure is also recorded in provider_api_failures so the
        // diagnostics page can answer "when did this provider start failing?"
        // without grepping the log.
        try {
            $response = Http::asForm()
                ->connectTimeout(10)
                ->timeout(20)
                ->post($this->authUrl(), [
                'grant_type'    => 'password',
                'client_id'     => $this->cfg['client_id'],
                'client_secret' => $this->cfg['client_secret'],
                'username'      => $this->cfg['username'],
                'password'      => $this->cfg['password'],
            ]);
        } catch (\Throwable $e) {
            // Connect timeout / DNS / TLS — no HTTP status exists.
            ProviderApiFailure::record($this->provider, 'auth', 'openid-connect/token', null, $e->getMessage());
            throw $e;
        }

        if ($response->failed()) {
            ProviderApiFailure::record(
                $this->provider,
                'auth',
                'openid-connect/token',
                $response->status(),
                $response->body(),
            );
        }

        $response->throw();

        ProviderApiFailure::markSuccess($this->provider);

        return $response->json('access_token');
    }

    private function ttl(): int
    {
        // Safee tokens expire in 300s (verified against Alrakeen) — refresh 30s early.
        return 270;
    }
}
