<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw per-provider site mirror synced from Safee (one row per provider site id).
 * Merged into a canonical {@see Site} by name during canonicalization.
 */
class ProviderSite extends Model
{
    protected $table = 'provider_sites';

    protected $fillable = [
        'provider',
        'dsco_site_id',
        'site_id',
        'name',
        'status',
        'dsco_last_seen_at',
    ];

    protected $casts = [
        'dsco_last_seen_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Canonical site this provider row rolls up into (by name). */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
