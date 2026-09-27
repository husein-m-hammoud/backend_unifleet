<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle's ongoing presence inside a zone it is not assigned to. Provisional
 * — promoted to a {@see Alert} once dwell exceeds the threshold (or the vehicle
 * goes offline in the zone). See the create-migration for the full lifecycle.
 */
class ZonePresence extends Model
{
    protected $fillable = [
        'vehicle_id',
        'zone_id',
        'entered_at',
        'last_seen_at',
        'alerted_at',
        'alert_id',
    ];

    protected $casts = [
        'entered_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'alerted_at'   => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
