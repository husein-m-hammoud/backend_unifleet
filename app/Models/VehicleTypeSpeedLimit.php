<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-vehicle-type override for the over-speed limit (km/h). Types without a
 * row fall back to the global `speed_limit` setting. A site-specific limit
 * overrides this while the vehicle is inside that site.
 */
class VehicleTypeSpeedLimit extends Model
{
    protected $fillable = [
        'type',
        'speed_limit',
    ];

    protected $casts = [
        'speed_limit' => 'integer',
    ];
}
