<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'dsco_vehicle_id',
        'dsco_uuid',
        'plate_no',
        'type',
        'dsco_company_id',
        'dsco_company_name',
        'dsco_site_id',
        'dsco_category_id',
        'current_driver_id',
        'vin',
        'vehicle_model',
        'vehicle_make',
        'dsco_created_at',
        'dsco_first_trip_date',
        'dsco_last_trip_date',
        'dsco_device_id',
        'device_sim',
        'device_imei',
        'device_type',
        'device_serial',
        'device_installation_date',
        'status',
        'dsco_last_seen_at',
    ];

    protected $casts = [
        'dsco_last_seen_at'       => 'datetime',
        'dsco_created_at'         => 'datetime',
        'dsco_first_trip_date'    => 'datetime',
        'dsco_last_trip_date'     => 'datetime',
        'device_installation_date'=> 'datetime',
    ];

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'current_driver_id');
    }

    public function driverAssignments(): HasMany
    {
        return $this->hasMany(VehicleDriverAssignment::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(VehiclePosition::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleEvent::class);
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class);
    }

    public function speedLogs(): HasMany
    {
        return $this->hasMany(VehicleSpeedLog::class);
    }

    public function weightLogs(): HasMany
    {
        return $this->hasMany(VehicleWeightLog::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
