<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trip extends Model
{
    protected $fillable = [
        'provider',
        'dsco_trip_id',
        'vehicle_id',
        'driver_id',
        'start_time',
        'end_time',
        'distance',
        'avg_speed',
        'max_speed',
        'idle_time',
        'driving_time',
        'completed',
        'start_lat',
        'start_lon',
        'start_alt',
        'end_lat',
        'end_lon',
        'end_alt',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'completed'  => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
