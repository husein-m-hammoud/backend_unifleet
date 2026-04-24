<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'type',
        'triggered_at',
        'resolved_at',
        'twilio_status',
        'meta',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at'  => 'datetime',
        'meta'         => 'array',
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
