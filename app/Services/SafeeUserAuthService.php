<?php

namespace App\Services;

use App\Models\DscoUserToken;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Per-user login against a Safee provider (Keycloak password grant), plus
 * transparent refresh-token handling. Users authenticate with their own Safee
 * credentials against the provider they pick at login (Alrakeen, saudiX, …);
 * the provider they logged in through is stored on the user so that token
 * refresh and vehicle/site scoping always use the correct provider config.
 *
 * Reuses the existing token storage (DscoUserToken) and the users.dsco_* columns
 * — those column names are retained to avoid a large rename migration; only the
 * data source changed (DSCO → Safee).
 */
class SafeeUserAuthService
{
    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    /**
     * Authenticate a user against the given provider and issue a Sanctum token.
     *
     * @param string $provider Provider key (must exist in config('services.safee.providers')).
     */
    public function login(string $provider, string $username, string $password): array
    {
        $safee = new SafeeAuthService($provider);

        $tokenData = $this->fetchTokenWithPassword($safee, $username, $password);

        // Key by (username, provider): the same username may exist on more than
        // one provider, and each must map to its own local user + scope.
        $user = User::firstOrCreate(
            ['dsco_username' => $username, 'provider' => $provider],
            ['name' => $username, 'email' => "{$username}@{$provider}.safee.local"]
        );

        $this->storeTokens($user, $tokenData);

        $vehicleIds = $this->fetchAccessibleVehicleIds($safee, $tokenData['access_token']);
        $user->update(['dsco_accessible_vehicle_ids' => $vehicleIds]);

        $sanctumToken = $user->createToken('safee-session')->plainTextToken;

        return [
            'token' => $sanctumToken,
            'user'  => $user->fresh(),
        ];
    }

    // -------------------------------------------------------------------------
    // Refresh (called by middleware before each API request)
    // -------------------------------------------------------------------------

    public function getValidAccessToken(User $user): ?string
    {
        $token = $user->dscoToken;

        if (! $token) {
            return null;
        }

        if ($token->expiresWithin(60)) {
            try {
                $safee = new SafeeAuthService($user->provider);
                $token = $this->refreshToken($safee, $user, $token);
            } catch (\Throwable $e) {
                Log::warning("[Safee Auth] Token refresh failed for user {$user->id}: " . $e->getMessage());
                return null;
            }
        }

        return $token->access_token;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function fetchTokenWithPassword(SafeeAuthService $safee, string $username, string $password): array
    {
        $cfg = $safee->config();

        $response = Http::asForm()->post($safee->authUrl(), [
            'grant_type'    => 'password',
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'username'      => $username,
            'password'      => $password,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Safee authentication failed: ' . ($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json();
    }

    private function refreshToken(SafeeAuthService $safee, User $user, DscoUserToken $token): DscoUserToken
    {
        $cfg = $safee->config();

        $response = Http::asForm()->post($safee->authUrl(), [
            'grant_type'    => 'refresh_token',
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Safee token refresh failed: ' . $response->body());
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

    private function fetchAccessibleVehicleIds(SafeeAuthService $safee, string $accessToken): array
    {
        $response = Http::asJson()->withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Accept'        => 'application/json',
        ])->post("{$safee->baseUrl()}/api/v2/vehicle/list-info", new \stdClass());

        if ($response->successful()) {
            $vehicles = $response->json('result') ?? [];
            $ids = array_column($vehicles, 'id');
            if (! empty($ids)) {
                return $ids;
            }
        }

        // Safee returned empty or failed — fall back to all synced vehicles for this provider.
        return Vehicle::where('provider', $safee->provider())->pluck('dsco_vehicle_id')->all();
    }
}
