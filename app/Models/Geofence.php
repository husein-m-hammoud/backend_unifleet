<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Geofence extends Model
{
    protected $fillable = [
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
}
