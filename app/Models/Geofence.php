<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Geofence extends Model
{
    protected $fillable = [
        'zone_id',
        'provider',
        'dsco_geofence_id',
        'dsco_uuid',
        'name',
        'code',
        'dsco_company_id',
        'description',
        'points_json',
        'status',
        'dsco_last_seen_at',
    ];

    protected $casts = [
        'points_json'       => 'array',
        'dsco_last_seen_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Canonical zone this provider geofence rolls up into (by name). */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
