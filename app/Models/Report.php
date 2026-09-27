<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = [
        'created_by',
        'type',
        'title',
        'generated_at',
        'period_from',
        'period_to',
        'content',
        'vehicle_id',
        'driver_id',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'period_from'  => 'datetime',
        'period_to'    => 'datetime',
        'content'      => 'array',
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
