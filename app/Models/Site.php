<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical, our-side site — one row per distinct site *name*, merging the
 * per-provider {@see ProviderSite} copies. Groups {@see Zone}s.
 */
class Site extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'speed_limit',
        'source',
        'created_by',
    ];

    protected $casts = [
        'speed_limit' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** User who manually added this site (null for provider-sourced). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Zones grouped under this site. */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    /** Raw provider site rows that roll up into this canonical site. */
    public function providerSites(): HasMany
    {
        return $this->hasMany(ProviderSite::class);
    }
}
