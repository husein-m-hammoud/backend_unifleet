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
     * Whether this account sees every zone/vehicle: any manager, or a scoped
     * `user` explicitly granted "access to all zones".
     */
    public function canSeeAllZones(): bool
    {
        return $this->isManager() || (bool) $this->all_zones;
    }

    /** Managers may always edit vehicles; scoped users need the explicit grant. */
    public function canEditVehicles(): bool
    {
        return $this->isManager() || (bool) $this->can_edit_vehicles;
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
     * all providers (Alrakeen + saudiX + …) merged into one fleet.
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
