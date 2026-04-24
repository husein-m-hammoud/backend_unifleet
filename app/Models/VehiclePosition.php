<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiclePosition extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $primaryKey = null;

    protected $fillable = [
        'time',
        'vehicle_id',
        'driver_id',
        'lat',
        'lon',
        'alt',
        'speed',
        'heading',
        'ignition_status',
        'odometer',
        'dsco_event_id',
        'event_name',
        'event_code',
    ];

    protected $casts = [
        'time' => 'datetime',
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
