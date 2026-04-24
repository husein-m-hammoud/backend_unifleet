<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleSpeedLog extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $primaryKey = null;

    protected $fillable = [
        'time',
        'vehicle_id',
        'speed',
        'max_speed',
        'avg_speed',
        'raw',
    ];

    protected $casts = [
        'time' => 'datetime',
        'raw'  => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
