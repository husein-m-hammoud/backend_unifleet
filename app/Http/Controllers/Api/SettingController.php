<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailEvent;
use Illuminate\Support\Facades\Mail;
use App\Models\Setting;
use App\Models\SettingChangelog;
use App\Models\Site;
use App\Models\VehicleTypeIdleThreshold;
use App\Models\VehicleTypeSpeedLimit;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/settings
     * Returns all settings grouped by their group key.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(function ($items, $group) {
            return [
                'group'    => $group,
                'settings' => $items->map(fn($s) => $this->format($s))->values(),
            ];
        })->values();

        return response()->json($grouped);
    }

    /**
     * PUT /api/settings/{key}
     * Update a single setting and record a changelog entry.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $setting = Setting::findOrFail($key);

        $request->validate([
            'value' => ['required', 'string', 'max:1000'],
        ]);

        $newRaw = $request->input('value');

        // Type-level validation
        match ($setting->type) {
            'integer' => $request->validate(['value' => 'integer']),
            'float'   => $request->validate(['value' => 'numeric']),
            'boolean' => $request->validate(['value' => 'in:true,false,1,0']),
            default   => null,
        };

        $oldRaw = $setting->value;

        if ($oldRaw === $newRaw) {
            return response()->json($this->format($setting));
        }

        $setting->update(['value' => $newRaw]);

        SettingChangelog::create([
            'setting_key'   => $key,
            'setting_label' => $setting->label,
            'old_value'     => $oldRaw,
            'new_value'     => $newRaw,
            'user_id'       => $request->user()->id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json($this->format($setting->fresh()));
    }

    /**
     * GET /api/settings/changelog
     * GET /api/settings/{key}/changelog
     * Returns recent changelog entries, optionally filtered by setting key.
     */
    public function changelog(Request $request, ?string $key = null): JsonResponse
    {
        $query = SettingChangelog::with('user:id,name')
            ->orderByDesc('changed_at')
            ->limit(200);

        if ($key) {
            $query->where('setting_key', $key);
        }

        return response()->json(
            $query->get()->map(fn($c) => [
                'id'            => $c->id,
                'setting_key'   => $c->setting_key,
                'setting_label' => $c->setting_label,
                'old_value'     => $c->old_value,
                'new_value'     => $c->new_value,
                'changed_at'    => $c->changed_at,
                'changed_by'    => $c->user?->name ?? 'System',
                'ip_address'    => $c->ip_address,
            ])
        );
    }

    /**
     * GET /api/settings/idle-thresholds
     * Idle thresholds at every level: the global default, a per-type override for
     * every vehicle type in the fleet, and a per-zone threshold for every canonical
     * zone. Effective priority when a vehicle is inside a zone:
     * zone threshold > type override > global default.
     */
    public function idleThresholds(Request $request): JsonResponse
    {
        $default   = (int) Setting::get('idle_threshold', 10);
        $overrides = VehicleTypeIdleThreshold::pluck('idle_threshold', 'type');

        $types = $request->user()->vehicleQuery()
            ->selectRaw('type, count(*) as vehicle_count')
            ->groupBy('type')
            ->orderByDesc('vehicle_count')
            ->get()
            ->map(fn ($t) => [
                'type'           => $t->type,
                'vehicle_count'  => (int) $t->vehicle_count,
                'idle_threshold' => $overrides[$t->type] ?? null,
                'effective'      => $overrides[$t->type] ?? $default,
            ]);

        $zones = Zone::active()
            ->with('site:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'site_id', 'idle_threshold'])
            ->map(fn (Zone $z) => [
                'id'             => $z->id,
                'name'           => $z->name,
                'site_name'      => $z->site?->name,
                'idle_threshold' => $z->idle_threshold,
            ]);

        return response()->json([
            'default' => $default,
            'types'   => $types,
            'zones'   => $zones,
        ]);
    }

    /**
     * PUT /api/settings/idle-thresholds
     * Upsert per-type and/or per-zone overrides. A value of null clears the
     * override (falls back down the priority chain). Body:
     * `{ types?: { TYPE: minutes|null }, zones?: { ZONE_ID: minutes|null } }`.
     */
    public function updateIdleThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types'   => ['sometimes', 'array'],
            'types.*' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'zones'   => ['sometimes', 'array'],
            'zones.*' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        foreach ($data['types'] ?? [] as $type => $minutes) {
            if ($minutes === null) {
                VehicleTypeIdleThreshold::where('type', $type)->delete();
            } else {
                VehicleTypeIdleThreshold::updateOrCreate(
                    ['type' => $type],
                    ['idle_threshold' => $minutes],
                );
            }
        }

        foreach ($data['zones'] ?? [] as $zoneId => $minutes) {
            Zone::whereKey($zoneId)->update(['idle_threshold' => $minutes]); // null clears
        }

        return $this->idleThresholds($request);
    }

    /**
     * GET /api/settings/speed-limits
     * Over-speed limits at every level: the global default, a per-type override
     * for every vehicle type in the fleet, and a per-site limit for every
     * canonical site. Effective priority when a vehicle is inside a site:
     * site limit > type override > global default.
     */
    /**
     * POST /api/settings/email-events/test
     *
     * Sends a real test email using one event's configured recipients, so
     * "will this actually reach anyone?" is answered by trying it rather than
     * by reading config. Manager-only.
     *
     * The signed-in manager's own address stands in as the event's `primary`,
     * which is what gives the credential events a To at all (their real primary
     * is whichever user the email is about). That also means a test can only ever
     * be sent to the person asking for it, plus the CC they already configured.
     */
    public function testEmailEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:60'],
        ]);

        $event = $data['event'] ?? 'ops_alert';

        if (! EmailEvent::isKnown($event)) {
            return response()->json(['message' => "Unknown email event [{$event}]."], 422);
        }

        $meta = EmailEvent::EVENTS[$event];
        $me   = $request->user()->email;

        // The tester stands in as `primary` ONLY for events that have one — a
        // credential email has no real target user during a test. For fleet-wide
        // events (reports, ops alerts) there is no primary, so injecting the
        // tester would make the test pass while a real alert reached nobody.
        // An empty result there is the truthful answer.
        $resolved = EmailEvent::recipients($event, $meta['primary'] === null ? null : $me);

        $mailer      = (string) config('mail.default');
        $deliverable = $mailer !== 'log';

        if (! $resolved['enabled']) {
            return response()->json([
                'event'   => $event,
                'label'   => $meta['label'],
                'message' => 'This email is switched off, so nothing would be sent.',
            ], 422);
        }

        if ($resolved['to'] === []) {
            return response()->json([
                'event'   => $event,
                'label'   => $meta['label'],
                'message' => $meta['primary'] === null
                    ? 'No recipients configured for this email, so nothing would be sent. Add an address to "Send to" first.'
                    : 'Your own account has no email address, so there is nowhere to send the test.',
            ], 422);
        }

        $body = "This is a test email from UNIFLEET.\n\n"
            . "Event: {$meta['label']}\n"
            . 'Sent to: ' . implode(', ', $resolved['to']) . "\n"
            . ($resolved['cc'] === [] ? '' : 'Copied to: ' . implode(', ', $resolved['cc']) . "\n")
            . "\nIf you received this, the recipients for this event are working.";

        try {
            Mail::raw($body, function ($message) use ($resolved, $meta) {
                $message->to($resolved['to'])
                    ->subject("[UNIFLEET] Test — {$meta['label']}");

                if ($resolved['cc'] !== []) {
                    $message->cc($resolved['cc']);
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'event'     => $event,
                'label'     => $meta['label'],
                'delivered' => false,
                'mailer'    => $mailer,
                'to'        => $resolved['to'],
                'cc'        => $resolved['cc'],
                'message'   => 'Send failed: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'event'     => $event,
            'label'     => $meta['label'],
            // False when the mailer is `log`: the send "succeeded" but went to a
            // file, which must not read as delivered.
            'delivered' => $deliverable,
            'mailer'    => $mailer,
            'to'        => $resolved['to'],
            'cc'        => $resolved['cc'],
            'message'   => $deliverable
                ? 'Test email sent.'
                : "Mailer is set to \"{$mailer}\", so the email was written to the server log instead of sent.",
        ]);
    }

    /**
     * GET /api/settings/email-events
     *
     * Per-event email recipients. `to`/`cc` are additive for events that have a
     * natural recipient (the user an account belongs to) and are the whole
     * recipient list for fleet-wide events.
     */
    public function emailEvents(): JsonResponse
    {
        $rows = EmailEvent::all()->keyBy('event');

        $events = collect(EmailEvent::EVENTS)->map(function ($meta, $key) use ($rows) {
            $row = $rows->get($key);

            return [
                'event'       => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                // Null means the event has no automatic recipient, so an empty
                // `to` stops it being sent at all — the UI needs to say so.
                'primary'     => $meta['primary'],
                'credentials' => $meta['credentials'],
                // False => the UI must not offer an extra-To field, and the API
                // rejects one. CC stays available.
                'extra_to'    => EmailEvent::allowsExtraTo($key),
                'enabled'     => $row ? (bool) $row->enabled : true,
                'to'          => $row->to ?? '',
                'cc'          => $row->cc ?? '',
                'updated_at'  => $row?->updated_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'events'       => $events,
            'mailer'       => config('mail.default'),
            'from_address' => config('mail.from.address'),
            // Surfaced so the UI can warn that nothing is actually delivered yet.
            'deliverable'  => config('mail.default') !== 'log',
        ]);
    }

    /**
     * PUT /api/settings/email-events
     *
     * Body: { events: { <event>: { enabled?: bool, to?: string, cc?: string } } }
     *
     * Address lists accept commas, semicolons or whitespace. Invalid entries are
     * dropped and reported back rather than silently stored, so a typo cannot
     * quietly stop an email reaching someone.
     */
    public function updateEmailEvents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events'             => ['required', 'array'],
            'events.*.enabled'   => ['sometimes', 'boolean'],
            'events.*.to'        => ['sometimes', 'nullable', 'string', 'max:2000'],
            'events.*.cc'        => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $rejected = [];

        foreach ($data['events'] as $event => $values) {
            if (! EmailEvent::isKnown($event)) {
                $rejected[$event] = ['unknown event'];
                continue;
            }

            $row = EmailEvent::config($event);

            foreach (['to', 'cc'] as $field) {
                if (! array_key_exists($field, $values)) {
                    continue;
                }

                // Credential emails are addressed to one person only. Refuse to
                // store extra To recipients even if a client sends them —
                // hiding the field in the UI is not a control.
                if ($field === 'to' && ! EmailEvent::allowsExtraTo($event)) {
                    if (EmailEvent::normalise($values[$field]) !== null) {
                        $rejected[$event]['to'] = ['this email is sent to one recipient only — use CC'];
                    }

                    $row->to = null;
                    continue;
                }

                $invalid = EmailEvent::invalidIn($values[$field]);

                if ($invalid !== []) {
                    $rejected[$event][$field] = $invalid;
                }

                $row->{$field} = EmailEvent::normalise($values[$field]);
            }

            if (array_key_exists('enabled', $values)) {
                $row->enabled = (bool) $values['enabled'];
            }

            $row->updated_by = $request->user()->id;
            $row->save();
        }

        return response()->json([
            'message'  => 'Email recipients updated.',
            // Addresses that were not valid emails and were therefore dropped.
            'rejected' => $rejected,
            'events'   => $this->emailEvents()->getData(true)['events'],
        ]);
    }

    public function speedLimits(Request $request): JsonResponse
    {
        $default       = (int) Setting::get('speed_limit', 120);
        $typeOverrides = VehicleTypeSpeedLimit::pluck('speed_limit', 'type');

        $types = $request->user()->vehicleQuery()
            ->selectRaw('type, count(*) as vehicle_count')
            ->groupBy('type')
            ->orderByDesc('vehicle_count')
            ->get()
            ->map(fn ($t) => [
                'type'          => $t->type,
                'vehicle_count' => (int) $t->vehicle_count,
                'speed_limit'   => $typeOverrides[$t->type] ?? null,
                'effective'     => $typeOverrides[$t->type] ?? $default,
            ]);

        $sites = Site::active()
            ->orderBy('name')
            ->get(['id', 'name', 'speed_limit'])
            ->map(fn (Site $s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'speed_limit' => $s->speed_limit,
            ]);

        return response()->json([
            'default' => $default,
            'types'   => $types,
            'sites'   => $sites,
        ]);
    }

    /**
     * PUT /api/settings/speed-limits
     * Upsert per-type and/or per-site overrides. A value of null clears the
     * override (falls back down the priority chain). Body:
     * `{ types?: { TYPE: kmh|null }, sites?: { SITE_ID: kmh|null } }`.
     */
    public function updateSpeedLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types'   => ['sometimes', 'array'],
            'types.*' => ['nullable', 'integer', 'min:1', 'max:400'],
            'sites'   => ['sometimes', 'array'],
            'sites.*' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);

        foreach ($data['types'] ?? [] as $type => $kmh) {
            if ($kmh === null) {
                VehicleTypeSpeedLimit::where('type', $type)->delete();
            } else {
                VehicleTypeSpeedLimit::updateOrCreate(
                    ['type' => $type],
                    ['speed_limit' => $kmh],
                );
            }
        }

        foreach ($data['sites'] ?? [] as $siteId => $kmh) {
            Site::whereKey($siteId)->update(['speed_limit' => $kmh]); // null clears
        }

        return $this->speedLimits($request);
    }

    private function format(Setting $s): array
    {
        return [
            'key'          => $s->key,
            'value'        => $s->value,
            'casted_value' => $s->casted_value,
            'type'         => $s->type,
            'label'        => $s->label,
            'description'  => $s->description,
            'group'        => $s->group,
        ];
    }
}
