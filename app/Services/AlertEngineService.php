<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Geofence;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Models\VehiclePosition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AlertEngineService
{
    private int   $speedLimit;
    private int   $cooldownMinutes;
    private float $lowFuelPct;
    /** @var array<int, array> Geofence rows keyed by id, loaded once per run */
    private array $geofences = [];

    public function __construct()
    {
        $this->speedLimit      = (int)   Setting::get('speed_limit', 120);
        $this->cooldownMinutes = (int)   Setting::get('alert_cooldown', 30);
        $this->lowFuelPct      = (float) Setting::get('low_fuel_pct', 20);
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
            ->get(['id', 'name', 'points_json'])
            ->toArray();

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
        }

        // Low fuel — runs for all vehicles regardless of position
        $this->checkFleetLowFuel($vehicleIds);
    }

    // ── Rule 1: Over-speed ────────────────────────────────────────────────────

    private function checkOverSpeed(VehiclePosition $pos, Vehicle $vehicle): void
    {
        $speed = (float) $pos->speed;
        if ($speed <= $this->speedLimit) {
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
                'speed'       => round($speed, 1),
                'speed_limit' => $this->speedLimit,
                'lat'         => (float) $pos->lat,
                'lon'         => (float) $pos->lon,
            ],
        ]);

        $this->setCooldown($vehicle->id, 'over_speed');

        Log::info(
            "[AlertEngine] over_speed: {$vehicle->plate_no} — " .
            "{$speed} km/h (limit {$this->speedLimit} km/h)"
        );
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
