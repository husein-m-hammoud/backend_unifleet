<?php

namespace App\Services;

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
        $response = Http::asForm()->post($this->authUrl(), [
            'grant_type'    => 'password',
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'username'      => $this->cfg['username'],
            'password'      => $this->cfg['password'],
        ]);

        $response->throw();

        return $response->json('access_token');
    }

    private function ttl(): int
    {
        // Safee tokens expire in 300s (verified against Alrakeen) — refresh 30s early.
        return 270;
    }
}
