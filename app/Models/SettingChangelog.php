<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingChangelog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'setting_key',
        'setting_label',
        'old_value',
        'new_value',
        'user_id',
        'ip_address',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
