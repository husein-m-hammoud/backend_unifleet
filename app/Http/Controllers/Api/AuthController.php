<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DscoUserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private DscoUserAuthService $auth) {}

    /**
     * POST /api/auth/login
     * Body: { "username": "...", "password": "..." }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $result = $this->auth->login($request->username, $request->password);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $user = $result['user'];

        return response()->json([
            'token' => $result['token'],
            'user'  => [
                'id'             => $user->id,
                'name'           => $user->name,
                'dsco_username'  => $user->dsco_username,
                'vehicles_count' => count($user->dsco_accessible_vehicle_ids ?? []),
            ],
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
        $user = $request->user();

        return response()->json([
            'id'             => $user->id,
            'name'           => $user->name,
            'dsco_username'  => $user->dsco_username,
            'vehicles_count' => count($user->dsco_accessible_vehicle_ids ?? []),
        ]);
    }
}
