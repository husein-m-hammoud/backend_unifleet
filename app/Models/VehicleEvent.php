<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleEvent extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $primaryKey = null;

    protected $fillable = [
        'time',
        'vehicle_id',
        'driver_id',
        'event_code',
        'event_name',
        'speed',
        'lat',
        'lon',
        'alt',
        'reason',
        'arguments',
    ];

    protected $casts = [
        'time'      => 'datetime',
        'arguments' => 'array',
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
