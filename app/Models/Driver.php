<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    protected $fillable = [
        'dsco_driver_id',
        'dsco_uuid',
        'name',
        'mobile',
        'gender',
        'email',
        'license_status',
        'residency_status',
        'access_key',
        'badge_number_1',
        'badge_number_2',
        'status',
        'dsco_last_seen_at',
        'dsco_created_at',
        'dsco_updated_at',
    ];

    protected $casts = [
        'dsco_last_seen_at' => 'datetime',
        'dsco_created_at'   => 'datetime',
        'dsco_updated_at'   => 'datetime',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'current_driver_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleDriverAssignment::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
