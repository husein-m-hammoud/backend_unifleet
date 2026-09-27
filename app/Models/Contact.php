<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person to notify about issues in a zone. Either responsible for specific
 * zones (via the contact_zone pivot) or for all zones (the {@see $all_zones}
 * flag), which also covers zones created after them.
 */
class Contact extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'description',
        'note',
        'all_zones',
        'created_by',
    ];

    protected $casts = [
        'all_zones' => 'boolean',
    ];

    /** Zones this contact is explicitly assigned to (empty when all_zones). */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'contact_zone')->withTimestamps();
    }

    /** User who added this contact. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
