<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * User management (Phase 3). Manager-only (owner/admin). Owner is the super
 * account: only an owner may create/edit/delete owner or admin accounts. Admins
 * manage regular `user` accounts. Deletes are soft (status = disabled) so rows
 * survive for change-log tracing.
 */
class UserController extends Controller
{
    /**
     * Page keys a scoped `user` may be granted. Managers see everything.
     * NB: 'fuel' is omitted — the Fuel Management page is hidden from the sidebar
     * (DashboardSidebar.tsx, 2026-09-09). Re-add 'fuel' here + in the frontend
     * GRANTABLE_PAGES (api.ts) when that nav link is restored.
     */
    public const GRANTABLE_PAGES = ['dashboard', 'map', 'vehicles', 'alerts', 'ai', 'reports'];

    private const ROLES = ['owner', 'admin', 'user'];

    /** GET /api/users — list all accounts with their grants. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureManager($request);

        $users = User::with(['grantedZones:id,name,site_id', 'grantedSites:id,name'])
            ->orderByRaw("array_position(ARRAY['owner','admin','user']::text[], role)")
            ->orderBy('name')
            ->get();

        return response()->json($users->map(fn (User $u) => $this->format($u)));
    }

    /** POST /api/users — create an account. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureManager($request);

        $data = $this->validatePayload($request, null);
        $this->ensureCanManageRole($request, $data['role']);

        // When emailing setup to the user we generate a strong password; otherwise
        // the admin typed one (validated below).
        $sendEmail = $this->wantsEmail($request);
        $plainPassword = $sendEmail ? $this->generatePassword() : $data['password'];

        $user = new User();
        $user->name              = $data['name'];
        $user->dsco_username     = $data['username'];
        $user->password          = $plainPassword; // hashed by cast
        $user->email             = $data['email'] ?? null;
        $user->role              = $data['role'];
        $user->status            = $data['status'] ?? 'active';
        $user->is_admin          = in_array($data['role'], ['owner', 'admin'], true);
        $user->allowed_pages     = $data['role'] === 'user' ? ($data['allowed_pages'] ?? []) : null;
        $user->can_edit_vehicles = $data['role'] === 'user' ? ($data['can_edit_vehicles'] ?? false) : true;
        $user->all_zones         = $data['role'] === 'user' ? ($data['all_zones'] ?? false) : false;
        $user->save();

        $this->syncGrants($user, $data);

        $payload = $this->format($user->fresh(['grantedZones', 'grantedSites']));

        // Email the credentials when requested (no-op-ish under MAIL_MAILER=log).
        // Also hand the generated password back so the admin can relay it while
        // SMTP isn't configured yet.
        if ($sendEmail && $user->email) {
            $this->tryNotify($user, new AccountCredentials($user->dsco_username, $plainPassword, true));
            $payload['email_sent'] = true;
        }
        if ($sendEmail) {
            $payload['generated_password'] = $plainPassword;
        }

        return response()->json($payload, 201);
    }

    /**
     * POST /api/users/{id}/password — change a user's password one of three ways:
     *   mode=link     → email a reset link (user picks their own password)
     *   mode=generate → generate a strong password, set it, email it
     *   mode=manual   → set the provided password
     */
    public function changePassword(Request $request, int $id): JsonResponse
    {
        $this->ensureManager($request);

        $user = User::findOrFail($id);
        $this->ensureCanManageRole($request, $user->role);

        $data = $request->validate([
            'mode'     => ['required', Rule::in(['link', 'generate', 'manual'])],
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'min:8', 'max:255'],
        ]);

        if ($data['mode'] === 'link') {
            if (! $user->email) {
                throw new AccessDeniedHttpException('This user has no email address to send a reset link to.');
            }
            Password::sendResetLink(['email' => $user->email]);
            return response()->json(['message' => 'Reset link sent.', 'email_sent' => true]);
        }

        $plain = $data['mode'] === 'generate' ? $this->generatePassword() : $data['password'];
        $user->forceFill(['password' => $plain])->save(); // hashed by cast
        $user->tokens()->delete(); // sign the user out everywhere

        $response = ['message' => 'Password updated.'];
        if ($data['mode'] === 'generate') {
            if ($user->email) {
                $this->tryNotify($user, new AccountCredentials($user->dsco_username, $plain, false));
                $response['email_sent'] = true;
            }
            $response['generated_password'] = $plain;
        }

        return response()->json($response);
    }

    /** PUT /api/users/{id} — update an account. */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureManager($request);

        $user = User::findOrFail($id);
        $this->ensureCanManageRole($request, $user->role); // may act on the CURRENT role
        $data = $this->validatePayload($request, $user);
        $this->ensureCanManageRole($request, $data['role']); // ...and the target role

        // Guard: an owner must remain. Don't let the last owner be demoted/disabled.
        if ($user->isOwner() && ($data['role'] !== 'owner' || ($data['status'] ?? 'active') === 'disabled')) {
            $this->ensureNotLastOwner($user);
        }

        $user->name          = $data['name'];
        $user->dsco_username  = $data['username'];
        $user->email          = $data['email'] ?? null;
        $user->role           = $data['role'];
        $user->status         = $data['status'] ?? $user->status;
        $user->is_admin       = in_array($data['role'], ['owner', 'admin'], true);
        $user->allowed_pages  = $data['role'] === 'user' ? ($data['allowed_pages'] ?? []) : null;
        $user->can_edit_vehicles = $data['role'] === 'user' ? ($data['can_edit_vehicles'] ?? false) : true;
        $user->all_zones      = $data['role'] === 'user' ? ($data['all_zones'] ?? false) : false;

        if (! empty($data['password'])) {
            $user->password = $data['password']; // hashed by cast
        }
        $user->save();

        $this->syncGrants($user, $data);

        return response()->json($this->format($user->fresh(['grantedZones', 'grantedSites'])));
    }

    /** DELETE /api/users/{id} — soft-delete (status = disabled). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureManager($request);

        $user = User::findOrFail($id);
        $this->ensureCanManageRole($request, $user->role);

        if ($user->id === $request->user()->id) {
            throw new AccessDeniedHttpException('You cannot deactivate your own account.');
        }
        if ($user->isOwner()) {
            $this->ensureNotLastOwner($user);
        }

        $user->update(['status' => 'disabled']);
        $user->tokens()->delete(); // revoke active sessions

        return response()->json(['message' => 'User deactivated', 'id' => $user->id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validatePayload(Request $request, ?User $existing): array
    {
        $userId = $existing?->id;

        // On create, a password is only required when the admin is NOT emailing
        // setup to the user (email mode generates one). On edit, passwords are
        // changed via the dedicated change-password endpoint, never here.
        $passwordRequired = ! $existing && ! $this->wantsEmail($request);

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'username'            => ['required', 'string', 'max:255', Rule::unique('users', 'dsco_username')->ignore($userId)],
            'email'               => [$this->wantsEmail($request) && ! $existing ? 'required' : 'nullable', 'email', 'max:255'],
            'password'            => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'send_email'          => ['sometimes', 'boolean'],
            'role'                => ['required', Rule::in(self::ROLES)],
            'status'              => ['sometimes', Rule::in(['active', 'disabled'])],
            'allowed_pages'       => ['sometimes', 'array'],
            'allowed_pages.*'     => [Rule::in(self::GRANTABLE_PAGES)],
            'can_edit_vehicles'   => ['sometimes', 'boolean'],
            'all_zones'           => ['sometimes', 'boolean'],
            'zone_ids'            => ['sometimes', 'array'],
            'zone_ids.*'          => ['integer', 'exists:zones,id'],
            'site_ids'            => ['sometimes', 'array'],
            'site_ids.*'          => ['integer', 'exists:sites,id'],
        ]);

        return $data;
    }

    /** Whether the admin asked to email account setup (default true). */
    private function wantsEmail(Request $request): bool
    {
        return $request->has('send_email') ? $request->boolean('send_email') : true;
    }

    /** A strong random password (mixed case, digits, symbols). */
    private function generatePassword(): string
    {
        return Str::password(14);
    }

    /** Send a notification, swallowing transport errors so the request still succeeds. */
    private function tryNotify(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            \Log::warning('[UserController] notification failed: ' . $e->getMessage());
        }
    }

    private function syncGrants(User $user, array $data): void
    {
        // Grants only apply to scoped users; clear them for managers.
        if ($user->role !== 'user') {
            $user->grantedZones()->sync([]);
            $user->grantedSites()->sync([]);
            return;
        }
        $user->grantedZones()->sync($data['zone_ids'] ?? []);
        $user->grantedSites()->sync($data['site_ids'] ?? []);
    }

    private function format(User $u): array
    {
        return [
            'id'                => $u->id,
            'name'              => $u->name,
            'username'          => $u->dsco_username,
            'email'             => $u->email,
            'role'              => $u->role,
            'status'            => $u->status,
            'is_owner'          => $u->isOwner(),
            'is_manager'        => $u->isManager(),
            'allowed_pages'     => $u->allowed_pages ?? [],
            'can_edit_vehicles' => $u->canEditVehicles(),
            'all_zones'         => (bool) $u->all_zones,
            'zone_ids'          => $u->grantedZones->pluck('id')->all(),
            'site_ids'          => $u->grantedSites->pluck('id')->all(),
            'zones'             => $u->grantedZones->map(fn ($z) => ['id' => $z->id, 'name' => $z->name])->values(),
            'sites'             => $u->grantedSites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'vehicles_count'    => $u->vehicleQuery()->count(),
            'created_at'        => $u->created_at,
        ];
    }

    private function ensureManager(Request $request): void
    {
        if (! $request->user()?->isManager()) {
            throw new AccessDeniedHttpException('Manager access required.');
        }
    }

    /**
     * An admin can only manage regular `user` accounts. Only an owner may create
     * or touch owner/admin accounts.
     */
    private function ensureCanManageRole(Request $request, string $targetRole): void
    {
        $actor = $request->user();
        if ($actor->isOwner()) {
            return; // owner manages everyone
        }
        // actor is an admin
        if (in_array($targetRole, ['owner', 'admin'], true)) {
            throw new AccessDeniedHttpException('Only an owner can manage owner or admin accounts.');
        }
    }

    private function ensureNotLastOwner(User $user): void
    {
        $otherOwners = User::where('role', 'owner')
            ->where('status', 'active')
            ->where('id', '!=', $user->id)
            ->count();

        if ($otherOwners === 0) {
            throw new AccessDeniedHttpException('Cannot remove the last active owner.');
        }
    }
}
