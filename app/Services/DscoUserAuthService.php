<?php

namespace App\Services;

use App\Models\DscoUserToken;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DscoUserAuthService
{
    private string $authUrl;
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->authUrl      = config('services.dsco.auth_url');
        $this->clientId     = config('services.dsco.client_id');
        $this->clientSecret = config('services.dsco.client_secret');
        $this->baseUrl      = rtrim(config('services.dsco.base_url'), '/');
    }

    // -------------------------------------------------------------------------
    // Login: authenticate user against DSCO, store tokens, return User
    // -------------------------------------------------------------------------

    public function login(string $username, string $password): array
    {
        // 1. Get tokens from DSCO
        $tokenData = $this->fetchTokenWithPassword($username, $password);

        // 2. Find or create local user
        $user = User::firstOrCreate(
            ['dsco_username' => $username],
            ['name' => $username, 'email' => $username . '@dsco.local']
        );

        // 3. Store DSCO tokens encrypted in DB
        $this->storeTokens($user, $tokenData);

        // 4. Fetch accessible vehicles using the fresh token and cache on user
        $vehicleIds = $this->fetchAccessibleVehicleIds($tokenData['access_token']);
        $user->update(['dsco_accessible_vehicle_ids' => $vehicleIds]);

        // 5. Issue Sanctum token (valid 8 hours by default)
        $sanctumToken = $user->createToken('dsco-session')->plainTextToken;

        return [
            'token' => $sanctumToken,
            'user'  => $user->fresh(),
        ];
    }

    // -------------------------------------------------------------------------
    // Token refresh — called by middleware before each API request
    // -------------------------------------------------------------------------

    /**
     * Returns a valid DSCO access token for the user.
     * Refreshes automatically if the token is about to expire.
     */
    public function getValidAccessToken(User $user): ?string
    {
        $dscoToken = $user->dscoToken;

        if (! $dscoToken) {
            return null;
        }

        if ($dscoToken->expiresWithin(60)) {
            try {
                $dscoToken = $this->refreshToken($user, $dscoToken);
            } catch (\Throwable $e) {
                Log::warning("[DSCO Auth] Token refresh failed for user {$user->id}: " . $e->getMessage());
                return null;
            }
        }

        return $dscoToken->access_token;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function fetchTokenWithPassword(string $username, string $password): array
    {
        $response = Http::asForm()->post($this->authUrl, [
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $username,
            'password'      => $password,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'DSCO authentication failed: ' . ($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json();
    }

    private function refreshToken(User $user, DscoUserToken $dscoToken): DscoUserToken
    {
        $response = Http::asForm()->post($this->authUrl, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $dscoToken->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('DSCO token refresh failed: ' . $response->body());
        }

        return $this->storeTokens($user, $response->json());
    }

    private function storeTokens(User $user, array $tokenData): DscoUserToken
    {
        $expiresIn = (int) ($tokenData['expires_in'] ?? 300);

        return DscoUserToken::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token'  => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? '',
                'expires_at'    => Carbon::now()->addSeconds($expiresIn),
            ]
        );
    }

    private function fetchAccessibleVehicleIds(string $accessToken): array
    {
        $response = Http::asJson()->withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Accept'        => 'application/json',
        ])->post("{$this->baseUrl}/api/v2/vehicle/list-info", new \stdClass());

        if (! $response->successful()) {
            return [];
        }

        $vehicles = $response->json('result') ?? [];

        return array_column($vehicles, 'id');
    }
}
