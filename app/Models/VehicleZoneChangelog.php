<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry per vehicle → zone assignment change: who, how (manual UI vs Excel
 * import), when, and the before/after zone names. See the create-migration.
 */
class VehicleZoneChangelog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_id',
        'plate_no',
        'old_zones',
        'new_zones',
        'source',
        'user_id',
        'ip_address',
        'changed_at',
    ];

    protected $casts = [
        'old_zones'  => 'array',
        'new_zones'  => 'array',
        'changed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
