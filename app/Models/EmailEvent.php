<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Recipient configuration for one outgoing email event.
 *
 * EVENTS below is the single registry the whole system agrees on — the API, the
 * Settings UI, the notifications and the console commands all read it, so a new
 * email cannot drift out of sync with its configuration.
 */
class EmailEvent extends Model
{
    protected $fillable = ['event', 'enabled', 'to', 'cc', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * Every configurable email.
     *
     *   primary      — who automatically receives it (null = recipients come only
     *                  from `to`, so an empty `to` means it is not sent)
     *   credentials  — true when the body contains a password or a reset link.
     *                  Anyone added to `to`/`cc` can read it, which for a reset
     *                  link means they could take the account over. Surfaced as a
     *                  warning in the UI rather than silently allowed.
     */
    public const EVENTS = [
        'account_created' => [
            'label'       => 'Account created',
            'description' => 'Sent when a manager creates a user with "Email account setup" enabled.',
            'primary'     => 'The new user',
            'credentials' => true,
        ],
        'password_changed' => [
            'label'       => 'Password changed by a manager',
            'description' => 'Sent when a manager generates and emails a new password for a user.',
            'primary'     => 'The affected user',
            'credentials' => true,
        ],
        'password_reset_link' => [
            'label'       => 'Forgot-password reset link',
            'description' => 'Sent when someone uses "Forgot password?" on the login screen.',
            'primary'     => 'The requesting user',
            'credentials' => true,
        ],
        'report_daily' => [
            'label'       => 'Daily fleet report',
            'description' => 'Scheduled 06:00 Asia/Riyadh, covering the previous day.',
            'primary'     => null,
            'credentials' => false,
        ],
        'report_weekly' => [
            'label'       => 'Weekly fleet report',
            'description' => 'Scheduled Sunday 06:30 Asia/Riyadh, covering the previous 7 days.',
            'primary'     => null,
            'credentials' => false,
        ],
        'ops_alert' => [
            'label'       => 'System & telemetry alerts',
            'description' => 'Pipeline gone stale / back online, and the diagnostics test alert. Goes to whoever maintains the system.',
            'primary'     => null,
            'credentials' => false,
        ],
    ];

    public static function isKnown(string $event): bool
    {
        return array_key_exists($event, self::EVENTS);
    }

    /** The stored row for an event, creating a disabled-by-default one if missing. */
    public static function config(string $event): self
    {
        return static::firstOrCreate(
            ['event' => $event],
            ['enabled' => true, 'to' => null, 'cc' => null],
        );
    }

    /**
     * Resolve the final recipient lists for an event.
     *
     * @param  string|null  $primary  The event's natural recipient (e.g. the user
     *                                the account belongs to), if any.
     * @return array{to:list<string>,cc:list<string>,enabled:bool}
     */
    public static function recipients(string $event, ?string $primary = null): array
    {
        $empty = ['to' => [], 'cc' => [], 'enabled' => false];

        try {
            if (! static::isKnown($event)) {
                Log::warning("[email] Unknown email event [{$event}] — not sending.");

                return $empty;
            }

            $row = static::config($event);

            if (! $row->enabled) {
                return $empty;
            }

            $to = static::parse($row->to);

            if ($primary !== null && filter_var($primary, FILTER_VALIDATE_EMAIL)) {
                // The person the email is about always comes first.
                $to = array_merge([$primary], $to);
            }

            $to = array_values(array_unique($to));

            // Someone already receiving it directly should not also be CC'd.
            $cc = array_values(array_diff(static::parse($row->cc), $to));

            return ['to' => $to, 'cc' => $cc, 'enabled' => true];
        } catch (\Throwable $e) {
            // A misconfigured recipient table must never break the thing that is
            // trying to send — but it also must not silently swallow the email,
            // so fall back to the primary recipient alone.
            Log::warning("[email] Could not resolve recipients for [{$event}]: " . $e->getMessage());

            return $primary !== null
                ? ['to' => [$primary], 'cc' => [], 'enabled' => true]
                : $empty;
        }
    }

    /**
     * Split a stored list into valid addresses. Accepts commas, semicolons and
     * whitespace, because people paste all three.
     *
     * @return list<string>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []),
            fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL),
        )));
    }

    /** Normalise a submitted list back to storage form, dropping invalid entries. */
    public static function normalise(?string $raw): ?string
    {
        $parsed = static::parse($raw);

        return $parsed === [] ? null : implode(', ', $parsed);
    }

    /** Addresses in the submitted value that are not valid emails (for a UI warning). */
    public static function invalidIn(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []),
            fn ($e) => $e !== '' && ! filter_var($e, FILTER_VALIDATE_EMAIL),
        ));
    }
}
