<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleFuelLog extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $primaryKey = null;

    protected $fillable = [
        'time',
        'vehicle_id',
        'fuel_liters',
        'fuel_pct',
        'total_fuel_used',
        'total_idle_fuel_used',
        'fuel_consumption_per_100km',
        'range_km',
        'fuel_low_indicator',
    ];

    protected $casts = [
        'time'               => 'datetime',
        'fuel_low_indicator' => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
