<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'dsco_username',
        'provider',
        'is_admin',
        'role',
        'status',
        'allowed_pages',
        'can_edit_vehicles',
        'all_zones',
        'dsco_accessible_vehicle_ids',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'           => 'datetime',
            'password'                    => 'hashed',
            'is_admin'                    => 'boolean',
            'can_edit_vehicles'           => 'boolean',
            'all_zones'                   => 'boolean',
            'allowed_pages'               => 'array',
            'dsco_accessible_vehicle_ids' => 'array',
        ];
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    /** Top-level super-account: manages admins, can't be demoted/deleted by an admin. */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** owner or admin — sees the whole fleet and manages zones/sites/users. */
    public function isManager(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    /**
     * UNIMAC helpdesk account. Full read visibility of the fleet and every
     * non-administrative page, but NOT a manager: it cannot touch users, zones,
     * sites, contacts or settings, and cannot delete or edit vehicles.
     *
     * Deliberately kept OUT of isManager(). Every administrative write in the
     * app gates on isManager() (or the `manager` middleware), so leaving support
     * out of it means those gates exclude support automatically rather than each
     * one needing to remember a new role.
     */
    public function isSupport(): bool
    {
        return $this->role === 'support';
    }

    /**
     * May this account READ the administrative pages — users, contacts and the
     * System/diagnostics page? True for managers and for support.
     *
     * Read only. Support must never pass a write gate, which is enforced
     * separately: the `manager:read` / `owner:read` middleware admits support
     * only for safe HTTP methods, and the controllers' own write guards still
     * call isManager()/isOwner() directly.
     */
    public function canViewAdmin(): bool
    {
        return $this->isManager() || $this->isSupport();
    }

    /** Roles that are never zone-scoped: they see every vehicle in the fleet. */
    public function seesWholeFleet(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'support'], true);
    }

    /**
     * Whether this account sees every zone/vehicle: an unscoped role, or a
     * scoped `user` explicitly granted "access to all zones".
     */
    public function canSeeAllZones(): bool
    {
        return $this->seesWholeFleet() || (bool) $this->all_zones;
    }

    /**
     * Managers may always edit vehicles; scoped users need the explicit grant.
     * Support is read-only, and UserController forces its column to false, so it
     * falls through to false here.
     */
    public function canEditVehicles(): bool
    {
        return $this->isManager() || (bool) $this->can_edit_vehicles;
    }

    /**
     * Whether this account reads every saved report or only its own.
     *
     * Reports are normally private to their creator (`reports.created_by`), and
     * that includes managers. Support is the one exception: a helpdesk agent
     * answering "what did last week's report say" cannot regenerate someone
     * else's snapshot, so they read all of them. This is deliberately the one
     * place where support sees MORE than an admin - change it here if that
     * asymmetry is unwanted, or widen it to isManager() for consistency.
     */
    public function canSeeAllReports(): bool
    {
        return $this->isSupport();
    }

    /** Zones granted directly to this user. */
    public function grantedZones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'user_zone');
    }

    /** Whole sites granted to this user (covers all of the site's zones, incl. future ones). */
    public function grantedSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'user_site');
    }

    /**
     * Every zone id this user can see: zones granted directly plus all zones under
     * any granted site. Managers aren't scoped and never call this.
     *
     * @return int[]
     */
    public function effectiveZoneIds(): array
    {
        $direct  = $this->grantedZones()->pluck('zones.id');
        $viaSite = Zone::whereIn('site_id', $this->grantedSites()->pluck('sites.id'))->pluck('id');

        return $direct->merge($viaSite)->unique()->values()->all();
    }

    /** Send the SPA-targeted password-reset link instead of the default backend route. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordLink($token));
    }

    /**
     * Mail recipients for a notification: this user, plus any extra To addresses
     * configured for the notification's email event.
     *
     * This override is the ONLY place extra To addresses can be added —
     * MailChannel reads the To from the notifiable's route, not from the
     * MailMessage (CC/BCC are read from the message; see the
     * ResolvesEventRecipients trait).
     *
     * @return string|array<string>
     */
    public function routeNotificationForMail($notification = null)
    {
        // Notifications that aren't event-aware keep the plain behaviour.
        if (! $notification || ! method_exists($notification, 'emailEvent')) {
            return $this->email;
        }

        $resolved = \App\Models\EmailEvent::recipients($notification->emailEvent(), $this->email);

        // A disabled event yields no recipients; returning [] makes MailChannel
        // skip the send rather than mailing the wrong people.
        return $resolved['to'];
    }

    public function dscoToken(): HasOne
    {
        return $this->hasOne(DscoUserToken::class);
    }

    /** Local Vehicle models this user has access to, based on DSCO vehicle IDs. */
    public function accessibleVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'dsco_vehicle_id', 'id')
            ->whereIn('dsco_vehicle_id', $this->dsco_accessible_vehicle_ids ?? []);
    }

    /**
     * Returns a query builder scoped to the vehicles this user can see.
     *
     * Managers (owner/admin) are provider-agnostic and see EVERY vehicle across
     * all providers (Alrakeen + DSCO + …) merged into one fleet.
     *
     * A scoped `user` sees only vehicles assigned to one of their granted zones
     * (direct or via a granted site). No grants ⇒ sees nothing. Vehicles with no
     * zone assignment (unrestricted) stay hidden from scoped users.
     *
     * Legacy provider accounts (pre-Phase-3, no zone grants but a provider +
     * accessible-id list) fall back to the old provider scoping so they aren't
     * silently blanked.
     */
    public function vehicleQuery()
    {
        if ($this->canSeeAllZones()) {
            return Vehicle::query();
        }

        $zoneIds = $this->effectiveZoneIds();

        if (! empty($zoneIds)) {
            return Vehicle::whereHas('zones', fn ($q) => $q->whereIn('zones.id', $zoneIds));
        }

        // Legacy fallback: an old provider user with accessible ids but no zone grants.
        if ($this->provider && ! empty($this->dsco_accessible_vehicle_ids)) {
            return Vehicle::whereIn('dsco_vehicle_id', $this->dsco_accessible_vehicle_ids)
                ->where('provider', $this->provider);
        }

        // Scoped user with no grants — sees nothing.
        return Vehicle::whereRaw('1 = 0');
    }
}
