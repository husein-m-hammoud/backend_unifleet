<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Geofence;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Models\VehiclePosition;
use App\Models\Zone;
use App\Models\ZonePresence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AlertEngineService
{
    /** A gap larger than this between idle fixes breaks the streak (device went offline). */
    private const MAX_IDLE_REPORT_GAP_MIN = 15;

    /**
     * Minutes without a fresh fix before we treat a vehicle as having gone quiet
     * *inside a zone* (used by the unauthorized-zone dwell logic to decide "the
     * truck stopped reporting while parked in a wrong zone"). This is a short,
     * fixed detector — distinct from the long, admin-configurable "No signal"
     * threshold below.
     */
    private const ZONE_OFFLINE_AFTER_MIN = 30;

    private int   $speedLimit;
    private int   $cooldownMinutes;
    private float $lowFuelPct;
    private int   $idleThresholdMin;
    private int   $unauthorizedZoneThresholdMin;
    /**
     * Minutes without a fresh GPS fix before a vehicle is flagged "No signal"
     * (dead/offline tracker) and an alert is raised. Admin-configurable in hours
     * via the `no_signal_threshold_hours` setting. Keep the frontend
     * (src/lib/api.ts) and DashboardController summary in sync with this.
     */
    private int   $noSignalThresholdMin;
    /** @var array<int, array> Geofence rows keyed by id, loaded once per run */
    private array $geofences = [];
    /** @var int[] Ids of zones flagged "open to all" — never raise unauthorized alerts. */
    private array $openZoneIds = [];
    /** @var array<string,int> Per-vehicle-type idle threshold overrides (minutes). */
    private array $idleThresholdByType = [];
    /** @var array<string,int> Per-vehicle-type over-speed limit overrides (km/h). */
    private array $speedLimitByType = [];
    /** @var array<int,int> Canonical zone id → its site id (only zones with a site). */
    private array $zoneSiteMap = [];
    /** @var array<int,int> Site id → its over-speed limit (km/h) — only sites with one set. */
    private array $siteSpeedLimits = [];
    /** @var array<int,string> Site id → name, for sites that have a speed limit. */
    private array $siteNames = [];
    /** @var array<int,int> Canonical zone id → its idle threshold (min) — only zones with one set. */
    private array $zoneIdleThresholds = [];
    /** @var array<int,string> Zone id → name, for zones that have an idle threshold. */
    private array $zoneIdleNames = [];

    public function __construct()
    {
        $this->speedLimit                   = (int)   Setting::get('speed_limit', 120);
        $this->cooldownMinutes              = (int)   Setting::get('alert_cooldown', 30);
        $this->lowFuelPct                   = (float) Setting::get('low_fuel_pct', 20);
        $this->idleThresholdMin             = (int)   Setting::get('idle_threshold', 10);
        $this->unauthorizedZoneThresholdMin = (int)   Setting::get('unauthorized_zone_threshold', 15);
        // Stored in hours for the operator; used internally in minutes. Floor at
        // 5 min so a misconfigured 0 can't flag the whole fleet as "No signal".
        $this->noSignalThresholdMin         = max(5, (int) round(((float) Setting::get('no_signal_threshold_hours', 24)) * 60));
        $this->idleThresholdByType          = \App\Models\VehicleTypeIdleThreshold::pluck('idle_threshold', 'type')->all();
        $this->speedLimitByType             = \App\Models\VehicleTypeSpeedLimit::pluck('speed_limit', 'type')->all();
    }

    /**
     * Resolve the excessive-idle threshold (minutes) that applies to a vehicle at
     * a position. Priority: a zone the vehicle is physically inside (most
     * restrictive threshold wins) overrides the vehicle-type override, which
     * overrides the global default. A zone threshold applies to EVERY vehicle
     * idling inside it regardless of type — mirrors {@see resolveSpeedLimit()}.
     *
     * @return array{threshold:int, source:string, zone_name:?string}
     */
    private function resolveIdleThreshold(Vehicle $vehicle, float $lat, float $lon): array
    {
        $zone = $this->zoneIdleThresholdAt($lat, $lon);
        if ($zone !== null) {
            return ['threshold' => $zone['threshold'], 'source' => 'zone', 'zone_name' => $zone['name']];
        }

        if (isset($this->idleThresholdByType[$vehicle->type])) {
            return ['threshold' => (int) $this->idleThresholdByType[$vehicle->type], 'source' => 'type', 'zone_name' => null];
        }

        return ['threshold' => $this->idleThresholdMin, 'source' => 'default', 'zone_name' => null];
    }

    /**
     * The most restrictive zone idle threshold whose geofence contains the point,
     * or null when the vehicle isn't inside any zone that has a threshold set.
     *
     * @return array{threshold:int, name:?string}|null
     */
    private function zoneIdleThresholdAt(float $lat, float $lon): ?array
    {
        if (empty($this->zoneIdleThresholds)) {
            return null;
        }

        $best = null;
        foreach ($this->geofences as $fence) {
            $zoneId = $fence['zone_id'] ?? null;
            if (! $zoneId || ! isset($this->zoneIdleThresholds[$zoneId])) {
                continue;
            }

            $points = is_array($fence['points_json'])
                ? $fence['points_json']
                : json_decode($fence['points_json'], true);
            if (empty($points) || ! $this->pointInPolygon($lat, $lon, $points)) {
                continue;
            }

            $threshold = (int) $this->zoneIdleThresholds[$zoneId];
            if ($best === null || $threshold < $best['threshold']) {
                $best = ['threshold' => $threshold, 'name' => $this->zoneIdleNames[$zoneId] ?? null];
            }
        }

        return $best;
    }

    /**
     * Run alert checks for the given vehicle IDs.
     * Reads each vehicle's latest valid GPS position and evaluates all rules.
     */
    public function checkVehicles(array $vehicleIds): void
    {
        if (empty($vehicleIds)) {
            return;
        }

        // Load geofences once for the whole batch
        $this->geofences = Geofence::active()
            ->whereNotNull('points_json')
            ->get(['id', 'name', 'points_json', 'zone_id'])
            ->toArray();

        // Zones open to every vehicle are skipped by the unauthorized-zone check.
        $this->openZoneIds = Zone::where('is_open', true)->pluck('id')->all();

        // Site-scoped speed limits: a vehicle inside a geofence whose canonical
        // zone rolls up to a site with a `speed_limit` is held to that limit
        // (overriding its type limit). Loaded once per batch.
        $this->zoneSiteMap = Zone::whereNotNull('site_id')->pluck('site_id', 'id')->all();
        $sitesWithLimit    = \App\Models\Site::whereNotNull('speed_limit')->get(['id', 'name', 'speed_limit']);
        $this->siteSpeedLimits = $sitesWithLimit->pluck('speed_limit', 'id')->all();
        $this->siteNames       = $sitesWithLimit->pluck('name', 'id')->all();

        // Zone-scoped idle thresholds: a vehicle idling inside a geofence whose
        // canonical zone has an `idle_threshold` is held to that threshold
        // (overriding its type threshold). Loaded once per batch.
        $zonesWithIdle = Zone::whereNotNull('idle_threshold')->get(['id', 'name', 'idle_threshold']);
        $this->zoneIdleThresholds = $zonesWithIdle->pluck('idle_threshold', 'id')->all();
        $this->zoneIdleNames      = $zonesWithIdle->pluck('name', 'id')->all();

        // Latest valid position per vehicle (DISTINCT ON via subquery)
        $positions = VehiclePosition::whereIn('vehicle_id', $vehicleIds)
            ->where('lat', '!=', 0)
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get()
            ->unique('vehicle_id');

        foreach ($positions as $pos) {
            $vehicle = Vehicle::find($pos->vehicle_id);
            if (!$vehicle) {
                continue;
            }

            $this->checkOverSpeed($pos, $vehicle);
            $this->checkGeofenceExit($pos, $vehicle);
            $this->checkIdle($pos, $vehicle);
        }

        // Low fuel — runs for all vehicles regardless of position
        $this->checkFleetLowFuel($vehicleIds);
    }

    // ── Rule 1: Over-speed ────────────────────────────────────────────────────

    private function checkOverSpeed(VehiclePosition $pos, Vehicle $vehicle): void
    {
        $speed = (float) $pos->speed;

        // Effective limit depends on WHERE the vehicle is and WHAT type it is:
        // site limit (if inside one) > per-type override > global default.
        $resolved = $this->resolveSpeedLimit($vehicle, (float) $pos->lat, (float) $pos->lon);
        $limit    = $resolved['limit'];

        if ($speed <= $limit) {
            return;
        }

        if ($this->inCooldown($vehicle->id, 'over_speed')) {
            return;
        }

        Alert::create([
            'vehicle_id'   => $vehicle->id,
            'driver_id'    => $pos->driver_id,
            'type'         => 'over_speed',
            'triggered_at' => $pos->time ?? now(),
            'meta'         => [
                'speed'        => round($speed, 1),
                'speed_limit'  => $limit,
                'limit_source' => $resolved['source'],   // site | type | default
                'site_name'    => $resolved['site_name'], // set only for source=site
                'lat'          => (float) $pos->lat,
                'lon'          => (float) $pos->lon,
            ],
        ]);

        $this->setCooldown($vehicle->id, 'over_speed');

        $where = $resolved['site_name'] ? " in {$resolved['site_name']}" : '';
        Log::info(
            "[AlertEngine] over_speed: {$vehicle->plate_no} — " .
            "{$speed} km/h (limit {$limit} km/h, {$resolved['source']}{$where})"
        );
    }

    /**
     * Resolve the over-speed limit that applies to a vehicle at a position.
     *
     * Priority: a site the vehicle is physically inside (most restrictive limit
     * wins) overrides the vehicle-type limit, which overrides the global default.
     * A site limit applies to EVERY vehicle inside that site regardless of type —
     * e.g. inside Qiddiya (50 km/h) a truck whose type limit is 120 is still
     * flagged at 51+.
     *
     * @return array{limit:int, source:string, site_name:?string}
     */
    private function resolveSpeedLimit(Vehicle $vehicle, float $lat, float $lon): array
    {
        $site = $this->siteSpeedLimitAt($lat, $lon);
        if ($site !== null) {
            return ['limit' => $site['limit'], 'source' => 'site', 'site_name' => $site['name']];
        }

        if (isset($this->speedLimitByType[$vehicle->type])) {
            return ['limit' => (int) $this->speedLimitByType[$vehicle->type], 'source' => 'type', 'site_name' => null];
        }

        return ['limit' => $this->speedLimit, 'source' => 'default', 'site_name' => null];
    }

    /**
     * The most restrictive site speed limit whose geofence contains the point, or
     * null when the vehicle isn't inside any site that has a limit configured.
     *
     * @return array{limit:int, name:?string}|null
     */
    private function siteSpeedLimitAt(float $lat, float $lon): ?array
    {
        if (empty($this->siteSpeedLimits)) {
            return null;
        }

        $best = null;
        foreach ($this->geofences as $fence) {
            $zoneId = $fence['zone_id'] ?? null;
            if (! $zoneId) {
                continue;
            }
            $siteId = $this->zoneSiteMap[$zoneId] ?? null;
            if (! $siteId || ! isset($this->siteSpeedLimits[$siteId])) {
                continue;
            }

            $points = is_array($fence['points_json'])
                ? $fence['points_json']
                : json_decode($fence['points_json'], true);
            if (empty($points) || ! $this->pointInPolygon($lat, $lon, $points)) {
                continue;
            }

            $limit = (int) $this->siteSpeedLimits[$siteId];
            if ($best === null || $limit < $best['limit']) {
                $best = ['limit' => $limit, 'name' => $this->siteNames[$siteId] ?? null];
            }
        }

        return $best;
    }

    // ── Rule 2: Geofence exit ─────────────────────────────────────────────────

    private function checkGeofenceExit(VehiclePosition $pos, Vehicle $vehicle): void
    {
        if (empty($this->geofences)) {
            return;
        }

        $lat = (float) $pos->lat;
        $lon = (float) $pos->lon;

        // Determine whether the vehicle is inside ANY active geofence right now
        $insideNow  = false;
        $insideFence = null;

        foreach ($this->geofences as $fence) {
            $points = is_array($fence['points_json'])
                ? $fence['points_json']
                : json_decode($fence['points_json'], true);

            if (empty($points)) {
                continue;
            }

            if ($this->pointInPolygon($lat, $lon, $points)) {
                $insideNow  = true;
                $insideFence = $fence;
                break;
            }
        }

        // Enforce zone assignment via dwell-based presence tracking. Runs every
        // poll — including when the vehicle is outside every fence — so a departed
        // vehicle's provisional presence is cleared.
        $this->reconcileZonePresence($pos, $vehicle, $insideNow ? $insideFence : null);

        $stateKey = "geofence_state_{$vehicle->id}";
        $wasInside = Cache::get($stateKey); // null = first observation

        // Persist the new state (2-hour TTL — survives multiple poll cycles)
        Cache::put($stateKey, $insideNow, now()->addHours(2));

        // Alert only when we have a confirmed transition: inside → outside
        if ($wasInside === true && !$insideNow) {
            if ($this->inCooldown($vehicle->id, 'geofence_exit')) {
                return;
            }

            // Find the closest geofence to name the exit point
            $exitFence = $this->closestGeofence($lat, $lon);

            Alert::create([
                'vehicle_id'   => $vehicle->id,
                'driver_id'    => $pos->driver_id,
                'type'         => 'geofence_exit',
                'triggered_at' => $pos->time ?? now(),
                'meta'         => [
                    'lat'           => $lat,
                    'lon'           => $lon,
                    'geofence_id'   => $exitFence['id']   ?? null,
                    'geofence_name' => $exitFence['name'] ?? null,
                ],
            ]);

            $this->setCooldown($vehicle->id, 'geofence_exit');

            Log::info(
                "[AlertEngine] geofence_exit: {$vehicle->plate_no} — " .
                "exited '{$exitFence['name']}'"
            );
        }
    }

    // ── Rule 6: Unauthorized zone dwell ───────────────────────────────────────

    /**
     * Dwell-based enforcement of a vehicle's zone assignment.
     *
     * A vehicle assigned to specific zones ("can enter") should not work in a
     * zone outside that set. Merely passing through is fine, so instead of
     * alerting on entry we track a provisional {@see ZonePresence}. Only once the
     * vehicle has dwelled longer than the `unauthorized_zone_threshold` setting —
     * or has gone offline while still inside — is the presence promoted to a real
     * `zone_unauthorized` alert. The alert's dwell/idle meta keeps updating while
     * the vehicle remains. Leaving the zone deletes the presence (the alert row,
     * once raised, persists as a record).
     *
     * Vehicles with NO assignment are unrestricted; zones flagged `is_open` are
     * open to all — both skip the check. The fence's canonical zone_id is the
     * authority (both providers' copies of a zone share it).
     *
     * @param array{id:int,name:string,zone_id:?int}|null $fence  the fence the vehicle is inside, or null
     */
    private function reconcileZonePresence(VehiclePosition $pos, Vehicle $vehicle, ?array $fence): void
    {
        $unauthorizedZoneId = $this->unauthorizedZoneFor($vehicle, $fence);

        $presence = ZonePresence::where('vehicle_id', $vehicle->id)->first();

        // Not in an unauthorized zone (left, or now in an allowed/open one):
        // the visit is over — drop the provisional record.
        if ($unauthorizedZoneId === null) {
            $presence?->delete();
            return;
        }

        $now = $pos->time ?? now();

        // Moved from one unauthorized zone to a different one — start fresh.
        if ($presence && $presence->zone_id !== $unauthorizedZoneId) {
            $presence->delete();
            $presence = null;
        }

        if (! $presence) {
            $presence = ZonePresence::create([
                'vehicle_id'   => $vehicle->id,
                'zone_id'      => $unauthorizedZoneId,
                'entered_at'   => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            $presence->last_seen_at = $now;
        }

        $dwellMinutes = (int) abs($presence->entered_at->diffInMinutes($now));
        $isIdle       = $pos->ignition_status === 'IgnitionOn';
        $isOffline    = $pos->time && $pos->time->lt(now()->subMinutes(self::ZONE_OFFLINE_AFTER_MIN));

        $zoneName = $fence['name'] ?? null;
        $meta = [
            'zone_id'       => $unauthorizedZoneId,
            'zone_name'     => $zoneName,
            'entered_at'    => $presence->entered_at->toIso8601String(),
            'dwell_minutes' => $dwellMinutes,
            'threshold'     => $this->unauthorizedZoneThresholdMin,
            'idle'          => $isIdle,
            'offline'       => (bool) $isOffline,
            'lat'           => (float) $pos->lat,
            'lon'           => (float) $pos->lon,
        ];

        if ($presence->alerted_at === null) {
            // Promote to a real alert once it has overstayed, or if it went dark
            // inside the zone (we can no longer see it leave).
            if ($dwellMinutes >= $this->unauthorizedZoneThresholdMin || $isOffline) {
                $alert = Alert::create([
                    'vehicle_id'   => $vehicle->id,
                    'driver_id'    => $pos->driver_id,
                    'type'         => 'zone_unauthorized',
                    'triggered_at' => $now,
                    'meta'         => $meta,
                ]);
                $presence->alerted_at = now();
                $presence->alert_id   = $alert->id;

                Log::info(
                    "[AlertEngine] zone_unauthorized: {$vehicle->plate_no} — " .
                    "{$dwellMinutes} min in unassigned zone '{$zoneName}'" .
                    ($isOffline ? ' (offline in zone)' : '')
                );
            }
        } elseif ($presence->alert_id) {
            // Already alerted — keep the alert's "time in zone" fresh while it stays.
            Alert::where('id', $presence->alert_id)->update(['meta' => $meta]);
        }

        $presence->save();
    }

    /**
     * Re-evaluate every open provisional presence, promoting any whose vehicle
     * has now overstayed OR gone dark inside the zone. This MUST run over ALL
     * presences (not just vehicles that reported this cycle): a vehicle that goes
     * offline inside an unauthorized zone drops out of the incremental poll, so
     * the per-fix path would never re-check it. Call on the full poll only.
     */
    public function sweepZonePresences(): void
    {
        $this->openZoneIds = Zone::where('is_open', true)->pluck('id')->all();

        $presences = ZonePresence::whereNull('alerted_at')->get();

        foreach ($presences as $presence) {
            // A zone flagged open-to-all since the visit began — drop it.
            if (in_array($presence->zone_id, $this->openZoneIds, true)) {
                $presence->delete();
                continue;
            }

            $pos = VehiclePosition::where('vehicle_id', $presence->vehicle_id)
                ->where('lat', '!=', 0)
                ->orderByDesc('time')
                ->first();

            if (! $pos || ! $pos->time) {
                continue;
            }

            $dwellMinutes = (int) abs($presence->entered_at->diffInMinutes($pos->time));
            $isOffline    = $pos->time->lt(now()->subMinutes(self::ZONE_OFFLINE_AFTER_MIN));

            if ($dwellMinutes < $this->unauthorizedZoneThresholdMin && ! $isOffline) {
                continue; // still within grace and reporting — nothing to do
            }

            $zone = Zone::find($presence->zone_id);
            $meta = [
                'zone_id'       => $presence->zone_id,
                'zone_name'     => $zone?->name,
                'entered_at'    => $presence->entered_at->toIso8601String(),
                'dwell_minutes' => $dwellMinutes,
                'threshold'     => $this->unauthorizedZoneThresholdMin,
                'idle'          => $pos->ignition_status === 'IgnitionOn',
                'offline'       => (bool) $isOffline,
                'lat'           => (float) $pos->lat,
                'lon'           => (float) $pos->lon,
            ];

            $alert = Alert::create([
                'vehicle_id'   => $presence->vehicle_id,
                'driver_id'    => $pos->driver_id,
                'type'         => 'zone_unauthorized',
                'triggered_at' => $pos->time,
                'meta'         => $meta,
            ]);
            $presence->alerted_at = now();
            $presence->alert_id   = $alert->id;
            $presence->save();

            Log::info(
                "[AlertEngine] zone_unauthorized (sweep): vehicle #{$presence->vehicle_id} — " .
                "{$dwellMinutes} min in unassigned zone '{$zone?->name}'" .
                ($isOffline ? ' (offline in zone)' : '')
            );
        }
    }

    /**
     * The canonical zone id the vehicle is *unauthorized* to be in right now, or
     * null. Null when: outside all fences, the fence has no canonical zone, the
     * zone is open to all, the vehicle is unrestricted (no assignment), or the
     * vehicle is assigned to this very zone.
     *
     * @param array{id:int,name:string,zone_id:?int}|null $fence
     */
    private function unauthorizedZoneFor(Vehicle $vehicle, ?array $fence): ?int
    {
        $zoneId = $fence['zone_id'] ?? null;
        if (! $zoneId) {
            return null; // outside, or fence not mapped to a canonical zone
        }

        if (in_array($zoneId, $this->openZoneIds, true)) {
            return null; // open to all
        }

        $assigned = $vehicle->zones()->pluck('zones.id');
        if ($assigned->isEmpty()) {
            return null; // unrestricted — allowed everywhere
        }

        return $assigned->contains($zoneId) ? null : (int) $zoneId;
    }

    // ── Rule 4: Excessive idling ──────────────────────────────────────────────

    /**
     * Idling = engine running while stationary. Safee reports this state as
     * ignition_status "IgnitionOn" (speed 0). "Moving", "IgnitionOff" (parked)
     * and "Towing" are NOT idling and break the streak.
     */
    private function checkIdle(VehiclePosition $pos, Vehicle $vehicle): void
    {
        if ($pos->ignition_status !== 'IgnitionOn' || ! $pos->time) {
            return;
        }

        // Effective threshold depends on WHERE the vehicle is and WHAT type it is:
        // zone threshold (if inside one) > per-type override > global default.
        $resolved  = $this->resolveIdleThreshold($vehicle, (float) $pos->lat, (float) $pos->lon);
        $threshold = $resolved['threshold'];

        // Freshness guard — if the newest fix is stale the vehicle likely went
        // offline or was switched off; don't raise a live idling alert on old data.
        $staleAfter = max(15, $threshold * 2);
        if ($pos->time->lt(now()->subMinutes($staleAfter))) {
            return;
        }

        $idleMinutes = $this->idleStreakMinutes($vehicle->id, $pos->time);
        if ($idleMinutes < $threshold) {
            return;
        }

        if ($this->inCooldown($vehicle->id, 'idle')) {
            return;
        }

        Alert::create([
            'vehicle_id'   => $vehicle->id,
            'driver_id'    => $pos->driver_id,
            'type'         => 'idle',
            'triggered_at' => $pos->time,
            'meta'         => [
                'idle_minutes'     => $idleMinutes,
                'idle_threshold'   => $threshold,
                'threshold_source' => $resolved['source'],    // zone | type | default
                'zone_name'        => $resolved['zone_name'], // set only for source=zone
                'lat'              => (float) $pos->lat,
                'lon'              => (float) $pos->lon,
            ],
        ]);

        $this->setCooldown($vehicle->id, 'idle');

        $where = $resolved['zone_name'] ? " in {$resolved['zone_name']}" : '';
        Log::info(
            "[AlertEngine] idle: {$vehicle->plate_no} — " .
            "idling {$idleMinutes} min (threshold {$threshold} min, {$resolved['source']}{$where})"
        );
    }

    /**
     * Minutes the vehicle has been continuously idling — measured from the
     * newest position back to the start of the current unbroken IgnitionOn
     * streak. The streak ends at the first Moving/IgnitionOff/Towing row OR at
     * a reporting gap larger than MAX_IDLE_REPORT_GAP_MIN (a device idling with
     * the engine on reports roughly once a minute, so a longer gap means it
     * went offline/parked and we can't assume it kept idling across it).
     */
    private function idleStreakMinutes(int $vehicleId, Carbon $latest): int
    {
        $rows = VehiclePosition::where('vehicle_id', $vehicleId)
            ->where('time', '>=', $latest->copy()->subHours(6))
            ->where('time', '<=', $latest)
            ->orderByDesc('time')
            ->get(['time', 'ignition_status']);

        $start = $latest;
        $prev  = $latest;
        foreach ($rows as $row) {
            if ($row->ignition_status !== 'IgnitionOn') {
                break;
            }
            $t = Carbon::parse($row->time);
            if (abs($prev->diffInMinutes($t)) > self::MAX_IDLE_REPORT_GAP_MIN) {
                break;
            }
            $start = $t;
            $prev  = $t;
        }

        return (int) abs($start->diffInMinutes($latest));
    }

    // ── Rule 5: Signal lost / restored ────────────────────────────────────────

    /**
     * Sweep every given vehicle's latest fix and raise / clear "no signal"
     * alerts. Unlike the per-fix rules, this MUST be called with ALL vehicles
     * (not just ones that reported this cycle) — a vehicle that went dark has no
     * new fix, so it would never be re-evaluated otherwise. Detects both
     * transitions via a cached state flag:
     *   live → no_signal  → open a `no_signal` alert (meta.last_seen = last fix)
     *   no_signal → live  → auto-resolve the open `no_signal` alert (back online)
     */
    public function checkSignalState(array $vehicleIds): void
    {
        if (empty($vehicleIds)) {
            return;
        }

        // Latest valid position per vehicle
        $positions = VehiclePosition::whereIn('vehicle_id', $vehicleIds)
            ->where('lat', '!=', 0)
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get()
            ->unique('vehicle_id');

        $threshold = now()->subMinutes($this->noSignalThresholdMin);

        foreach ($positions as $pos) {
            $vehicle = Vehicle::find($pos->vehicle_id);
            if (! $vehicle) {
                continue;
            }

            $isNoSignal = $pos->time && $pos->time->lt($threshold);
            $this->applySignalTransition($vehicle, $pos, $isNoSignal);
        }
    }

    private function applySignalTransition(Vehicle $vehicle, VehiclePosition $pos, bool $isNoSignal): void
    {
        $stateKey    = "signal_state_{$vehicle->id}";
        $wasNoSignal = Cache::get($stateKey); // null = first observation

        // Persist the new state (long TTL so it survives many poll cycles)
        Cache::put($stateKey, $isNoSignal, now()->addDays(7));

        // First time we ever see this vehicle: seed state silently so we don't
        // fire a burst of alerts for vehicles that were already dark before this
        // feature shipped. Only genuine transitions from here on raise alerts.
        if ($wasNoSignal === null) {
            return;
        }

        // live → no_signal : the tracker went dark
        if (! $wasNoSignal && $isNoSignal) {
            Alert::create([
                'vehicle_id'   => $vehicle->id,
                'driver_id'    => $pos->driver_id,
                'type'         => 'no_signal',
                'triggered_at' => now(),
                'meta'         => [
                    'last_seen'       => $pos->time?->toISOString(),
                    'threshold_hours' => round($this->noSignalThresholdMin / 60, 2),
                    'lat'             => (float) $pos->lat,
                    'lon'             => (float) $pos->lon,
                ],
            ]);

            Log::info("[AlertEngine] no_signal: {$vehicle->plate_no} — last seen {$pos->time}");
            return;
        }

        // no_signal → live : the tracker came back. Resolve the open alert and
        // stamp the restoration on it so the UI can show "back online at …".
        if ($wasNoSignal && ! $isNoSignal) {
            $alerts = Alert::where('vehicle_id', $vehicle->id)
                ->where('type', 'no_signal')
                ->whereNull('resolved_at')
                ->get();

            foreach ($alerts as $alert) {
                $meta = $alert->meta ?? [];
                $meta['restored_at']  = now()->toISOString();
                $meta['back_online']  = true;
                $alert->update(['resolved_at' => now(), 'meta' => $meta]);
            }

            if ($alerts->isNotEmpty()) {
                Log::info("[AlertEngine] signal_restored: {$vehicle->plate_no} — back online");
            }
        }
    }

    // ── Point-in-polygon (ray casting) ────────────────────────────────────────

    /**
     * Standard ray casting algorithm.
     * Expects $points as [['lat' => ..., 'lon' => ...], ...]
     */
    private function pointInPolygon(float $lat, float $lon, array $points): bool
    {
        $n      = count($points);
        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $points[$i]['lat'];
            $yi = (float) $points[$i]['lon'];
            $xj = (float) $points[$j]['lat'];
            $yj = (float) $points[$j]['lon'];

            if (
                (($yi > $lon) !== ($yj > $lon)) &&
                ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi)
            ) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** Return the geofence whose centroid is closest to ($lat, $lon). */
    private function closestGeofence(float $lat, float $lon): array
    {
        $best    = [];
        $minDist = PHP_FLOAT_MAX;

        foreach ($this->geofences as $fence) {
            $points = is_array($fence['points_json'])
                ? $fence['points_json']
                : json_decode($fence['points_json'], true);

            if (empty($points)) {
                continue;
            }

            $cLat = array_sum(array_column($points, 'lat')) / count($points);
            $cLon = array_sum(array_column($points, 'lon')) / count($points);

            $d = ($lat - $cLat) ** 2 + ($lon - $cLon) ** 2;
            if ($d < $minDist) {
                $minDist = $d;
                $best    = $fence;
            }
        }

        return $best;
    }

    // ── Rule 3: Low fuel ─────────────────────────────────────────────────────

    private function checkFleetLowFuel(array $vehicleIds): void
    {
        // Latest fuel log per vehicle in one bulk query
        $fuelLogs = VehicleFuelLog::whereIn('vehicle_id', $vehicleIds)
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get()
            ->unique('vehicle_id');

        foreach ($fuelLogs as $fuel) {
            $vehicle = Vehicle::find($fuel->vehicle_id);
            if (!$vehicle) {
                continue;
            }
            $this->checkLowFuel($fuel, $vehicle);
        }
    }

    private function checkLowFuel(VehicleFuelLog $fuel, Vehicle $vehicle): void
    {
        $fuelPct = (float) $fuel->fuel_pct;

        // Trigger if below threshold OR if the device itself flags low fuel
        if ($fuelPct > $this->lowFuelPct && !$fuel->fuel_low_indicator) {
            return;
        }

        if ($this->inCooldown($vehicle->id, 'low_fuel')) {
            return;
        }

        Alert::create([
            'vehicle_id'   => $vehicle->id,
            'driver_id'    => null,
            'type'         => 'low_fuel',
            'triggered_at' => $fuel->time ?? now(),
            'meta'         => [
                'fuel_pct'     => round($fuelPct, 1),
                'low_fuel_pct' => $this->lowFuelPct,
                'fuel_liters'  => round((float) $fuel->fuel_liters, 1),
                'range_km'     => round((float) $fuel->range_km, 1),
            ],
        ]);

        $this->setCooldown($vehicle->id, 'low_fuel');

        Log::info(
            "[AlertEngine] low_fuel: {$vehicle->plate_no} — " .
            "{$fuelPct}% (threshold {$this->lowFuelPct}%)"
        );
    }

    // ── Cooldown helpers ──────────────────────────────────────────────────────

    private function inCooldown(int $vehicleId, string $type): bool
    {
        return Cache::has("alert_cd_{$vehicleId}_{$type}");
    }

    private function setCooldown(int $vehicleId, string $type): void
    {
        Cache::put(
            "alert_cd_{$vehicleId}_{$type}",
            true,
            now()->addMinutes($this->cooldownMinutes)
        );
    }
}
