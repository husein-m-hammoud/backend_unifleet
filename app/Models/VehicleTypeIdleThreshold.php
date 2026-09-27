<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-vehicle-type override for the excessive-idling threshold (minutes).
 * Types without a row fall back to the global `idle_threshold` setting.
 */
class VehicleTypeIdleThreshold extends Model
{
    protected $fillable = [
        'type',
        'idle_threshold',
    ];

    protected $casts = [
        'idle_threshold' => 'integer',
    ];
}
