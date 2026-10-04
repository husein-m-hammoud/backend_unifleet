<?php

namespace App\Services;

use App\Models\EmailEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers operational warnings to US (the operators), not to the client.
 *
 * Why this exists: the Sep 2026 outage lasted 14 days because `safee:health`
 * wrote a row into `alerts` and stopped there. Detection without delivery is
 * not monitoring. This is the delivery half.
 *
 * Currently email-only. It is a real channel ONLY once MAIL_MAILER is something
 * other than `log` — a log file nobody watches cannot page anyone, so
 * `configuredChannels()` deliberately reports nothing while the log mailer is
 * active, and SystemHealthService surfaces that as a failing check.
 *
 * Adding a channel is a `match` arm plus a config key: the dedupe, cooldown and
 * best-effort semantics below are channel-agnostic.
 *
 * Every send is best-effort: a notifier that throws would take down the
 * watchdog that called it.
 */
class OpsNotifier
{
    /**
     * Which channels are usable right now. Also surfaced by
     * SystemHealthService so the diagnostics page can say "nothing will tell
     * you the pipeline died".
     *
     * @return list<string>
     */
    public static function configuredChannels(): array
    {
        $channels = [];

        // A `log` mailer delivers to a file nobody watches, so it does not count
        // as a channel for alerting purposes.
        if (self::emailRecipients()['to'] !== [] && config('mail.default') !== 'log') {
            $channels[] = 'email';
        }

        return $channels;
    }

    /**
     * Send an operational notice. Returns the channels that accepted it.
     *
     * @param  string  $dedupeKey  Optional key; when given, the same key is not
     *                             re-sent within the cooldown window. Stops an
     *                             hourly watchdog paging every hour for days.
     * @return list<string>
     */
    public static function send(string $subject, string $body, ?string $dedupeKey = null, int $cooldownMinutes = 360): array
    {
        if ($dedupeKey !== null && ! self::shouldSend($dedupeKey, $cooldownMinutes)) {
            return [];
        }

        $sent = [];

        foreach (self::configuredChannels() as $channel) {
            $ok = match ($channel) {
                'email' => self::sendEmail($subject, $body),
                default => false,
            };

            if ($ok) {
                $sent[] = $channel;
            }
        }

        if ($sent === []) {
            // Still leave a trace, so the log shows the attempt even when no
            // channel is configured.
            Log::warning("[ops] {$subject} (no delivery channel configured)", ['body' => $body]);
        }

        return $sent;
    }

    private static function shouldSend(string $key, int $cooldownMinutes): bool
    {
        try {
            // add() is atomic: it returns false when the key already exists, so
            // two concurrent runs cannot both send.
            return Cache::add(
                "ops_notice_{$key}",
                now()->toIso8601String(),
                now()->addMinutes($cooldownMinutes),
            );
        } catch (\Throwable $e) {
            // Cache down — better to notify twice than not at all.
            return true;
        }
    }

    /**
     * Recipients for the `ops_alert` event, falling back to the legacy
     * OPS_ALERT_EMAIL env var so an install configured before per-event
     * recipients existed keeps working.
     *
     * @return array{to:list<string>,cc:list<string>}
     */
    private static function emailRecipients(): array
    {
        $resolved = EmailEvent::recipients('ops_alert');

        if ($resolved['to'] === []) {
            $legacy = EmailEvent::parse((string) config('services.ops.email'));

            if ($legacy !== [] && $resolved['enabled']) {
                $resolved['to'] = $legacy;
            }
        }

        return ['to' => $resolved['to'], 'cc' => $resolved['cc']];
    }

    private static function sendEmail(string $subject, string $body): bool
    {
        try {
            $recipients = self::emailRecipients();

            if ($recipients['to'] === []) {
                return false;
            }

            Mail::raw($body, function ($message) use ($subject, $recipients) {
                $message->to($recipients['to'])
                    ->subject("[UNIFLEET] {$subject}");

                if ($recipients['cc'] !== []) {
                    $message->cc($recipients['cc']);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ops] Email send threw: ' . $e->getMessage());

            return false;
        }
    }
}
