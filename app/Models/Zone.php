<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical, our-side zone — one row per distinct geofence *name*, merging the
 * per-provider {@see Geofence} polygons. Optionally grouped under a {@see Site}.
 */
class Zone extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'site_id',
        'status',
        'is_open',
        'idle_threshold',
        'source',
        'created_by',
    ];

    protected $casts = [
        'is_open'        => 'boolean',
        'idle_threshold' => 'integer',
    ];

    /** User who manually added this zone (null for provider-sourced). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Canonical site this zone belongs to (nullable). */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Raw provider geofence polygons that roll up into this zone. */
    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    /** Vehicles assigned to this zone ("can enter"). */
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'vehicle_zone');
    }

    /** Contacts explicitly assigned to this zone (excludes all_zones responsibles). */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_zone')->withTimestamps();
    }

    /**
     * Everyone responsible for this zone: contacts assigned to it *plus* every
     * contact flagged "all zones". Use for the "who to notify" lookup.
     */
    public function responsibleContacts()
    {
        return Contact::where('all_zones', true)
            ->orWhereHas('zones', fn ($q) => $q->whereKey($this->id))
            ->orderBy('name');
    }
}
