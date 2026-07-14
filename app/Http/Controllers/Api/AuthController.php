<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SafeeUserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private SafeeUserAuthService $auth) {}

    /**
     * GET /api/auth/providers  (public)
     * Provider options for the login screen radio buttons.
     */
    public function providers(): JsonResponse
    {
        $providers = collect(config('services.safee.providers', []))
            ->map(fn ($cfg, $key) => [
                'key'   => $key,
                'label' => $cfg['label'] ?? ucfirst($key),
            ])
            ->values();

        return response()->json($providers);
    }

    /**
     * POST /api/auth/login
     * Body: { "provider": "alrakeen", "username": "...", "password": "..." }
     */
    public function login(Request $request): JsonResponse
    {
        $providerKeys = array_keys(config('services.safee.providers', []));

        $request->validate([
            'provider' => ['required', 'string', Rule::in($providerKeys)],
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $result = $this->auth->login($request->provider, $request->username, $request->password);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        return response()->json([
            'token' => $result['token'],
            'user'  => $this->userPayload($result['user']),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload($user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'dsco_username'  => $user->dsco_username,
            'provider'       => $user->provider,
            'vehicles_count' => count($user->dsco_accessible_vehicle_ids ?? []),
        ];
    }
}
