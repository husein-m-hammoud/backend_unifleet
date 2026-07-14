<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Site extends Model
{
    protected $fillable = [
        'provider',
        'dsco_site_id',
        'name',
        'status',
        'dsco_last_seen_at',
    ];

    protected $casts = [
        'dsco_last_seen_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
