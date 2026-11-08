<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     * Body: { "username": "...", "password": "..." }
     *
     * Local account login — accounts are provisioned by us (no provider picker).
     * The background poller keeps pulling every provider via service accounts, so
     * an admin account sees the whole fleet (all providers) merged together.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('dsco_username', $request->username)->first();

        if (! $user || ! $user->password || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status === 'disabled') {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        $token = $user->createToken('session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * POST /api/auth/forgot-password  (public)
     * Body: { "login": "username-or-email" }
     *
     * Emails a reset link to the account's email if it has one. Always returns a
     * generic success so we don't leak which accounts exist / have email.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['login' => 'required|string']);
        $login = trim($request->input('login'));

        $user = User::where('dsco_username', $login)
            ->orWhere('email', $login)
            ->first();

        $generic = response()->json([
            'message' => 'If an account with that username or email exists and has an email address, a reset link has been sent.',
        ]);

        if (! $user || ! $user->email || $user->status === 'disabled') {
            return $generic;
        }

        // Broker keys on email; our override sends the SPA-targeted link.
        Password::sendResetLink(['email' => $user->email]);

        return $generic;
    }

    /**
     * POST /api/auth/reset-password  (public)
     * Body: { token, email, password, password_confirmation }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save(); // hashed by cast
                $user->tokens()->delete(); // invalidate existing sessions
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password has been reset. You can now sign in.']);
        }

        return response()->json(['message' => __($status)], 422);
    }

    /**
     * POST /api/auth/password  (authenticated, any role)
     * Body: { current_password, password, password_confirmation }
     *
     * Self-service password change. This is the ONLY way a role=user account can
     * change its own password: UserController::changePassword is manager-gated,
     * and forgot-password -> reset-password needs working outbound mail. Without
     * this, a user we provision is stuck forever with the password we generated
     * and emailed them, which is also the one most likely to have been exposed.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        // A token issued before the account was disabled still authenticates
        // (only UserController::destroy revokes tokens; editing status to
        // 'disabled' does not), so re-check status here rather than assume the
        // middleware caught it.
        if ($user->status === 'disabled') {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        // Deliberately NOT Laravel's `current_password` validation rule: that
        // rule validates against the default (session) guard, and this API
        // authenticates with Sanctum bearer tokens, so it would reject every
        // correct password. Checking the hash directly is the whole point.
        if (! $user->password || ! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        if (Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from your current one.'],
            ]);
        }

        $user->forceFill(['password' => $request->input('password')])->save(); // hashed by cast

        // Revoke every OTHER token. Changing a password is how someone locks out
        // a session they believe is compromised - leaving the old tokens valid
        // would make the whole action cosmetic. The token used for THIS request
        // survives, so the user is not bounced out of the device they just
        // changed it on.
        $revoked = $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->getKey())
            ->delete();

        return response()->json([
            'message'         => 'Password updated.',
            'sessions_ended'  => $revoked,
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
            'id'                => $user->id,
            'name'              => $user->name,
            'dsco_username'     => $user->dsco_username,
            'provider'          => $user->provider,
            'is_admin'          => (bool) $user->is_admin,
            'role'              => $user->role,
            'status'            => $user->status,
            'is_owner'          => $user->isOwner(),
            'is_manager'        => $user->isManager(),
            'allowed_pages'     => $user->allowed_pages ?? [],
            'can_edit_vehicles' => $user->canEditVehicles(),
            'vehicles_count'    => $user->vehicleQuery()->count(),
        ];
    }
}
