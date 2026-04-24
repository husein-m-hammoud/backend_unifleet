<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DscoUserToken extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        // Encrypt both tokens at rest — uses APP_KEY
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at'    => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True if the access token expires within the next $seconds seconds. */
    public function expiresWithin(int $seconds = 60): bool
    {
        return $this->expires_at->lte(Carbon::now()->addSeconds($seconds));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(Carbon::now());
    }
}
