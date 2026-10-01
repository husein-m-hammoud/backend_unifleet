<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One recorded Safee API failure. See the migration for why only failures are
 * stored and successes live in the cache.
 */
class ProviderApiFailure extends Model
{
    protected $fillable = [
        'provider', 'kind', 'endpoint', 'status_code', 'message', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    /**
     * Record a failure. Never throws — diagnostics must not be able to break the
     * poller they are observing (a full disk or a missing table would otherwise
     * turn an API hiccup into a crash).
     */
    public static function record(
        string $provider,
        string $kind,
        ?string $endpoint,
        ?int $statusCode,
        ?string $message,
    ): void {
        try {
            static::create([
                'provider'    => $provider,
                'kind'        => $kind,
                'endpoint'    => $endpoint,
                'status_code' => $statusCode,
                // Exception messages can carry a whole HTML error page; keep the
                // useful head and leave the table readable.
                'message'     => $message === null ? null : mb_substr(trim($message), 0, 500),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[diagnostics] Could not record provider API failure: ' . $e->getMessage());
        }
    }

    /**
     * Stamp "this provider answered successfully just now". Kept in the cache
     * rather than a table because the live poll succeeds every 30 seconds.
     *
     * A 30-day TTL means a long silence reads as "no recent success" instead of
     * quietly reporting a stale timestamp forever.
     */
    public static function markSuccess(string $provider): void
    {
        try {
            Cache::put(static::successKey($provider), now()->toIso8601String(), now()->addDays(30));
        } catch (\Throwable $e) {
            // Cache unavailable — not worth failing a good request over.
        }
    }

    /** ISO timestamp of this provider's last successful call, or null. */
    public static function lastSuccessAt(string $provider): ?string
    {
        try {
            return Cache::get(static::successKey($provider));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function successKey(string $provider): string
    {
        return "safee_last_success_{$provider}";
    }
}
