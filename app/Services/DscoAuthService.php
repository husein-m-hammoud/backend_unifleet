<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DscoAuthService
{
    private const CACHE_KEY = 'dsco_access_token';

    public function getToken(): string
    {
        return Cache::remember(self::CACHE_KEY, $this->ttl(), fn () => $this->fetchToken());
    }

    public function forgetToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function fetchToken(): string
    {
        $response = Http::asForm()->post(config('services.dsco.auth_url'), [
            'grant_type'    => 'password',
            'client_id'     => config('services.dsco.client_id'),
            'client_secret' => config('services.dsco.client_secret'),
            'username'      => config('services.dsco.username'),
            'password'      => config('services.dsco.password'),
        ]);

        $response->throw();

        return $response->json('access_token');
    }

    private function ttl(): int
    {
        // DSCO tokens typically expire in 300s — refresh 30s early to be safe
        return 270;
    }
}
