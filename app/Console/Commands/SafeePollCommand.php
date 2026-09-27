<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\ProviderSite;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use App\Models\VehicleFuelLog;
use App\Models\VehiclePosition;
use App\Models\VehicleSpeedLog;
use App\Models\VehicleWeightLog;
use App\Services\AlertEngineService;
use App\Services\SafeeApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Polls the Safee Tracking API and syncs data into the database.
 *
 *   php artisan safee:poll                 # all configured providers
 *   php artisan safee:poll alrakeen        # one provider
 *   php artisan safee:poll --force         # ignore the interval guard
 *   php artisan safee:poll --backfill      # also pull GPS history
 *
 * Every synced reference/transactional row is stamped with its provider so that
 * Alrakeen and saudiX (which can share numeric IDs) never collide. Soft-disable
 * of missing entities is scoped per provider.
 */
class SafeePollCommand extends Command
{
    protected $signature   = 'safee:poll
                                {provider? : Provider key (default: all configured)}
                                {--force     : Ignore interval check and run immediately}
                                {--live-only : Only sync live positions + run alert detection (fast, near-real-time map)}
                                {--backfill  : Fetch full position history and fill vehicle_positions}';
    protected $description = 'Poll the Safee Tracking API and sync data into the database';

    private SafeeApiService $api;
    private string $provider;

    public function handle(): int
    {
        // The full poll's trip/telemetry step (step 7) can exceed PHP's default
        // 128M and fatal mid-run, which silently freezes trips + fuel while live
        // positions keep flowing. Raise the ceiling so a full sync always finishes.
        // Override via SAFEE_POLL_MEMORY_LIMIT if a bigger fleet needs more.
        ini_set('memory_limit', env('SAFEE_POLL_MEMORY_LIMIT', '512M'));

        $providers = $this->argument('provider')
            ? [$this->argument('provider')]
            : array_keys(config('services.safee.providers', []));

        if (empty($providers)) {
            $this->error('[Safee] No providers configured in services.safee.providers.');
            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($providers as $provider) {
            if ($this->pollProvider($provider) === self::FAILURE) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function pollProvider(string $provider): int
    {
        $this->provider = $provider;

        try {
            $this->api = new SafeeApiService($provider);
        } catch (\Throwable $e) {
            $this->error("[Safee:{$provider}] " . $e->getMessage());
            return self::FAILURE;
        }

        // Fast path: positions + alerts only. Cadence is controlled by the
        // scheduler (every ~30s), so it bypasses the heavy-poll interval guard
        // and skips reference/trip/telemetry syncing.
        if ($this->option('live-only')) {
            try {
                $newPositionVehicleIds = $this->syncLiveState();
                $this->runAlertEngine($newPositionVehicleIds);
            } catch (\Throwable $e) {
                Log::error("[Safee:{$provider}] Live poll failed: " . $e->getMessage(), ['exception' => $e]);
                $this->error("[Safee:{$provider}] Live poll failed: " . $e->getMessage());
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        if (! $this->shouldRun()) {
            return self::SUCCESS;
        }

        $this->info("[Safee:{$provider}] Poll started at " . now()->toDateTimeString());

        try {
            $this->syncSites();
            $this->syncCategories();
            $this->syncGeofences();
            (new \App\Services\ZoneSiteSyncService())->run(); // canonical zones/sites by name
            $this->syncDrivers();
            $this->syncVehicles();
            $newPositionVehicleIds = $this->syncLiveState();
            $this->runAlertEngine($newPositionVehicleIds);
            $this->runSignalStateSweep();
            $this->runZonePresenceSweep();
            $this->syncTripsAndTelemetry();

            if ($this->option('backfill')) {
                $this->backfillPositions();
            }
        } catch (\Throwable $e) {
            Log::error("[Safee:{$provider}] Poll failed: " . $e->getMessage(), ['exception' => $e]);
            $this->error("[Safee:{$provider}] Poll failed: " . $e->getMessage());
            return self::FAILURE;
        }

        Cache::put($this->key('last_poll_ran_at'), now()->timestamp, now()->addHours(24));
        Cache::put($this->key('last_sync_timestamp'), microtime(true), now()->addHours(24));

        $this->info("[Safee:{$provider}] Poll completed at " . now()->toDateTimeString());
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Guards & helpers
    // -------------------------------------------------------------------------

    private function key(string $suffix): string
    {
        return "safee_{$this->provider}_{$suffix}";
    }

    private function shouldRun(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $intervalMinutes = (int) env('SAFEE_POLL_INTERVAL_MINUTES', env('DSCO_POLL_INTERVAL_MINUTES', 2));
        $lastRan         = Cache::get($this->key('last_poll_ran_at'));

        if ($lastRan === null) {
            return true;
        }

        return (now()->timestamp - $lastRan) >= ($intervalMinutes * 60);
    }

    private function lookbackTimestamp(): float
    {
        $cached = Cache::get($this->key('last_sync_timestamp'));

        if ($cached !== null) {
            return (float) $cached;
        }

        $hours = (int) env('SAFEE_LOOKBACK_HOURS', env('DSCO_LOOKBACK_HOURS', 24));
        return (float) now()->subHours($hours)->timestamp;
    }

    /**
     * Safee sends dates as second-based unix timestamps, but a few fields
     * (e.g. deviceInstallationDate) come in milliseconds, and some arrive as
     * scientific-notation strings ("1.780457369E9"). Normalise to Carbon.
     */
    private function tsToCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = (float) $value;
        if ($ts <= 0) {
            return null;
        }

        // > ~ year 5000 in seconds means the value is actually milliseconds.
        if ($ts > 1_000_000_000_000) {
            $ts /= 1000;
        }

        return Carbon::createFromTimestamp($ts);
    }

    // -------------------------------------------------------------------------
    // Step 1 — Sites
    // -------------------------------------------------------------------------

    private function syncSites(): void
    {
        $rows    = $this->api->getSites();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            ProviderSite::updateOrCreate(
                ['provider' => $this->provider, 'dsco_site_id' => $row['id']],
                [
                    'name'              => $row['name'],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        $this->disableMissing(ProviderSite::class, 'dsco_site_id', $seenIds);
        $this->line("[Safee:{$this->provider}] Sites synced: " . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 2 — Categories
    // -------------------------------------------------------------------------

    private function syncCategories(): void
    {
        $rows    = $this->api->getCategories();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Category::updateOrCreate(
                ['provider' => $this->provider, 'dsco_category_id' => $row['id']],
                [
                    'dsco_site_id'      => $row['siteId'] ?? null,
                    'dsco_parent_id'    => $row['parentId'] ?? null,
                    'name'              => $row['name'],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        $this->disableMissing(Category::class, 'dsco_category_id', $seenIds);
        $this->line("[Safee:{$this->provider}] Categories synced: " . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 3 — Geofences
    // -------------------------------------------------------------------------

    private function syncGeofences(): void
    {
        $rows    = $this->api->getGeofences();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Geofence::updateOrCreate(
                ['provider' => $this->provider, 'dsco_geofence_id' => $row['id']],
                [
                    'dsco_uuid'         => $row['uuid'] ?? null,
                    'name'              => $row['name'],
                    'code'              => $row['code'] ?? null,
                    'dsco_company_id'   => $row['companyId'] ?? null,
                    'description'       => $row['description'] ?? null,
                    'points_json'       => $row['points'] ?? [],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        $this->disableMissing(Geofence::class, 'dsco_geofence_id', $seenIds);
        $this->line("[Safee:{$this->provider}] Geofences synced: " . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 4 — Drivers  (Alrakeen has none; saudiX may)
    // -------------------------------------------------------------------------

    private function syncDrivers(): void
    {
        $rows    = $this->api->getDriverListInfo();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Driver::updateOrCreate(
                ['provider' => $this->provider, 'dsco_driver_id' => $row['id']],
                [
                    'dsco_uuid'         => $row['uuid'] ?? null,
                    'name'              => $row['name'],
                    'mobile'            => $row['mobile'] ?? null,
                    'gender'            => $row['gender'] ?? null,
                    'email'             => $row['email'] ?? null,
                    'license_status'    => $row['licenseStatus'] ?? null,
                    'residency_status'  => $row['residencyStatus'] ?? null,
                    'access_key'        => $row['accessKey'] ?? null,
                    'badge_number_1'    => $row['badgeNumber1'] ?? null,
                    'badge_number_2'    => $row['badgeNumber2'] ?? null,
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                    'dsco_created_at'   => $this->tsToCarbon($row['createdAt'] ?? null),
                    'dsco_updated_at'   => $this->tsToCarbon($row['updatedAt'] ?? null),
                ]
            );
        }

        $this->disableMissing(Driver::class, 'dsco_driver_id', $seenIds);
        $this->line("[Safee:{$this->provider}] Drivers synced: " . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 5 — Vehicles
    // -------------------------------------------------------------------------

    private function syncVehicles(): void
    {
        $rows    = $this->api->getVehicleListInfo();
        $now     = now();
        $seenIds = [];

        // Vehicles a manager explicitly deleted — never re-create them from the API.
        $blocked = \Illuminate\Support\Facades\DB::table('vehicle_blocklist')
            ->where('provider', $this->provider)
            ->pluck('dsco_vehicle_id')
            ->flip();

        foreach ($rows as $row) {
            if ($blocked->has($row['id'])) {
                continue; // deleted; skip re-import
            }

            $seenIds[] = $row['id'];

            $localDriverId = null;
            if (! empty($row['driver']['id'])) {
                $localDriverId = Driver::where('provider', $this->provider)
                    ->where('dsco_driver_id', $row['driver']['id'])
                    ->value('id');
            }

            $vehicle = Vehicle::updateOrCreate(
                ['provider' => $this->provider, 'dsco_vehicle_id' => $row['id']],
                [
                    'dsco_uuid'                => $row['uuid'] ?? null,
                    'plate_no'                 => $row['plateNo'],
                    'type'                     => $row['type'] ?? null,
                    'dsco_company_id'          => $row['company']['id'] ?? null,
                    'dsco_company_name'        => $row['company']['name'] ?? null,
                    'dsco_site_id'             => $row['site']['id'] ?? null,
                    'dsco_category_id'         => $row['category']['id'] ?? null,
                    'current_driver_id'        => $localDriverId,
                    'vin'                      => $row['vin'] ?? null,
                    'vehicle_model'            => $row['vehicleModel'] ?? null,
                    'vehicle_make'             => $row['vehicleMake'] ?? null,
                    'dsco_created_at'          => $this->tsToCarbon($row['createdAt'] ?? null),
                    'dsco_first_trip_date'     => $this->tsToCarbon($row['firstTripDate'] ?? null),
                    'dsco_last_trip_date'      => $this->tsToCarbon($row['lastTripDate'] ?? null),
                    'dsco_device_id'           => $row['device']['id'] ?? null,
                    'device_sim'               => $row['device']['sim'] ?? null,
                    'device_imei'              => $row['device']['imei'] ?? null,
                    'device_type'              => $row['device']['type'] ?? null,
                    'device_serial'            => $row['device']['serial'] ?? null,
                    'device_installation_date' => $this->tsToCarbon($row['deviceInstallationDate'] ?? null),
                    'status'                   => 'active',
                    'dsco_last_seen_at'        => $now,
                ]
            );

            $this->trackDriverAssignment($vehicle, $localDriverId);
        }

        $this->disableMissing(Vehicle::class, 'dsco_vehicle_id', $seenIds);
        $this->line("[Safee:{$this->provider}] Vehicles synced: " . count($seenIds));
    }

    private function trackDriverAssignment(Vehicle $vehicle, ?int $newDriverId): void
    {
        $openAssignment = VehicleDriverAssignment::where('vehicle_id', $vehicle->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        $previousDriverId = $openAssignment?->driver_id;

        if ($previousDriverId === $newDriverId) {
            return;
        }

        if ($openAssignment) {
            $openAssignment->update(['ended_at' => now()]);
        }

        VehicleDriverAssignment::create([
            'vehicle_id' => $vehicle->id,
            'driver_id'  => $newDriverId,
            'started_at' => now(),
            'ended_at'   => null,
        ]);
    }

    /** Soft-disable entities of this provider not returned this cycle. */
    private function disableMissing(string $modelClass, string $externalIdColumn, array $seenIds): void
    {
        if (empty($seenIds)) {
            return;
        }

        $modelClass::query()
            ->where('provider', $this->provider)
            ->whereNotIn($externalIdColumn, $seenIds)
            ->where('status', 'active')
            ->update(['status' => 'disabled']);
    }

    // -------------------------------------------------------------------------
    // Step 6 — Live state (positions)
    // -------------------------------------------------------------------------

    /** @return int[] local vehicle IDs that had a new position stored this cycle */
    private function syncLiveState(): array
    {
        $activeVehicles = Vehicle::active()->where('provider', $this->provider)->get();

        if ($activeVehicles->isEmpty()) {
            $this->line("[Safee:{$this->provider}] No active vehicles — skipping live state.");
            return [];
        }

        $vehicleMap = $activeVehicles->keyBy('dsco_vehicle_id');
        $dscoIds    = $vehicleMap->keys()->all();

        $now             = now();
        $stored          = 0;
        $skipped         = 0;
        $newVehicleIds   = [];

        foreach (array_chunk($dscoIds, 50) as $chunk) {
            $states  = $this->api->getVehicleLastState($chunk);
            $inserts = [];

            foreach ($states as $state) {
                $vehicle = $vehicleMap->get($state['id']);
                if (! $vehicle) {
                    continue;
                }

                $lat = $state['position']['lat'] ?? 0;
                $lon = $state['position']['lon'] ?? 0;

                // Skip no-GPS-fix rows (Safee returns 0,0 for offline vehicles).
                if ((float) $lat === 0.0 && (float) $lon === 0.0) {
                    continue;
                }

                // Dedup by device timestamp — skip unchanged (parked) vehicles.
                $dscoTimestamp = isset($state['date']) ? (int) $state['date'] : 0;
                $cacheKey      = "last_pos_ts_{$vehicle->id}";

                if ($dscoTimestamp > 0 && Cache::get($cacheKey) === $dscoTimestamp) {
                    $skipped++;
                    continue;
                }

                $driverId = null;
                if (! empty($state['driver']['id'])) {
                    $driverId = Driver::where('provider', $this->provider)
                        ->where('dsco_driver_id', $state['driver']['id'])
                        ->value('id');
                }

                $inserts[$vehicle->id] = [
                    'time'            => $dscoTimestamp > 0 ? Carbon::createFromTimestamp($dscoTimestamp) : $now,
                    'vehicle_id'      => $vehicle->id,
                    'driver_id'       => $driverId,
                    'lat'             => $lat,
                    'lon'             => $lon,
                    'alt'             => $state['position']['alt'] ?? 0,
                    'speed'           => $state['speed'] ?? 0,
                    'heading'         => $state['heading'] ?? 0,
                    'ignition_status' => $state['status'] ?? null,
                    'odometer'        => isset($state['odometer']) ? (float) $state['odometer'] : null,
                    'dsco_event_id'   => $state['event']['id'] ?? null,
                    'event_name'      => $state['event']['name'] ?? null,
                    'event_code'      => $state['event']['code'] ?? null,
                    '_dsco_ts'        => $dscoTimestamp,
                ];
            }

            if (! empty($inserts)) {
                $rows = array_map(function ($row) {
                    unset($row['_dsco_ts']);
                    return $row;
                }, $inserts);

                VehiclePosition::insert(array_values($rows));

                foreach ($inserts as $vehicleId => $row) {
                    if ($row['_dsco_ts'] > 0) {
                        Cache::put("last_pos_ts_{$vehicleId}", $row['_dsco_ts'], now()->addHours(1));
                    }
                }

                $stored       += count($inserts);
                $newVehicleIds = array_merge($newVehicleIds, array_keys($inserts));
            }
        }

        $this->line("[Safee:{$this->provider}] Live positions stored: {$stored}, skipped (no new fix): {$skipped}.");

        return $newVehicleIds;
    }

    private function runAlertEngine(array $vehicleIds): void
    {
        if (empty($vehicleIds)) {
            return;
        }

        try {
            (new AlertEngineService())->checkVehicles($vehicleIds);
        } catch (\Throwable $e) {
            Log::error('[AlertEngine] Failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->warn('[AlertEngine] Error: ' . $e->getMessage());
        }
    }

    /**
     * Signal-lost / restored detection. Runs over ALL active vehicles of this
     * provider (not just ones that reported) so vehicles that went dark are
     * still detected. Cheap enough for the 5-min full poll.
     */
    private function runSignalStateSweep(): void
    {
        try {
            $ids = Vehicle::active()->where('provider', $this->provider)->pluck('id')->all();
            (new AlertEngineService())->checkSignalState($ids);
        } catch (\Throwable $e) {
            Log::error('[AlertEngine] Signal sweep failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->warn('[AlertEngine] Signal sweep error: ' . $e->getMessage());
        }
    }

    /**
     * Promote any provisional unauthorized-zone presence whose vehicle has
     * overstayed or gone offline inside the zone. Runs over ALL open presences,
     * catching vehicles that dropped out of the incremental poll.
     */
    private function runZonePresenceSweep(): void
    {
        try {
            (new AlertEngineService())->sweepZonePresences();
        } catch (\Throwable $e) {
            Log::error('[AlertEngine] Zone presence sweep failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->warn('[AlertEngine] Zone presence sweep error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Step 7 — Trips + per-vehicle telemetry (fuel, speed, weight)
    // -------------------------------------------------------------------------

    private function syncTripsAndTelemetry(): void
    {
        $startDate = $this->lookbackTimestamp();
        $endDate   = microtime(true);

        $activeVehicles = Vehicle::active()->where('provider', $this->provider)->get();

        foreach ($activeVehicles as $vehicle) {
            $this->syncVehicleTrips($vehicle, $startDate, $endDate);
            $this->syncVehicleFuel($vehicle);
            $this->syncVehicleSpeed($vehicle);
            $this->syncVehicleWeight($vehicle);
        }
    }

    private function syncVehicleTrips(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        try {
            $trips = $this->api->getVehicleTrips($vehicle->dsco_vehicle_id, $startDate, $endDate);

            foreach ($trips as $t) {
                $driverId = null;
                if (! empty($t['driver']['id'])) {
                    $driverId = Driver::where('provider', $this->provider)
                        ->where('dsco_driver_id', $t['driver']['id'])
                        ->value('id');
                }

                Trip::updateOrCreate(
                    ['provider' => $this->provider, 'dsco_trip_id' => $t['id']],
                    [
                        'vehicle_id'   => $vehicle->id,
                        'driver_id'    => $driverId,
                        'start_time'   => $this->tsToCarbon($t['startTime'] ?? null),
                        'end_time'     => $this->tsToCarbon($t['endTime'] ?? null),
                        'distance'     => $t['distance'] ?? 0,
                        'avg_speed'    => $t['avgSpeed'] ?? 0,
                        'max_speed'    => $t['maxSpeed'] ?? 0,
                        'idle_time'    => $t['idleTime'] ?? 0,
                        'driving_time' => $t['drivingTime'] ?? 0,
                        'completed'    => $t['completed'] ?? false,
                        'start_lat'    => $t['startLocation']['lat'] ?? null,
                        'start_lon'    => $t['startLocation']['lon'] ?? null,
                        'start_alt'    => $t['startLocation']['alt'] ?? null,
                        'end_lat'      => $t['endLocation']['lat'] ?? null,
                        'end_lon'      => $t['endLocation']['lon'] ?? null,
                        'end_alt'      => $t['endLocation']['alt'] ?? null,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("[Safee:{$this->provider}] Trips failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleFuel(Vehicle $vehicle): void
    {
        try {
            $data = $this->api->getVehicleFuelData($vehicle->dsco_vehicle_id);
            if (empty($data)) {
                return;
            }

            VehicleFuelLog::insert([[
                'time'                       => now(),
                'vehicle_id'                 => $vehicle->id,
                'fuel_liters'                => $data['fuelLiters'] ?? null,
                'fuel_pct'                   => $data['fuelPercentage'] ?? null,
                'total_fuel_used'            => $data['totalFuelUsed'] ?? null,
                'total_idle_fuel_used'       => $data['totalIdleFuelUsed'] ?? null,
                'fuel_consumption_per_100km' => $data['fuelConsumptionPerHunderedKm'] ?? null,
                'range_km'                   => $data['rangeKm'] ?? null,
                'fuel_low_indicator'         => $data['fuelLowIndicatorOn'] ?? null,
            ]]);
        } catch (\Throwable $e) {
            Log::warning("[Safee:{$this->provider}] Fuel data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleSpeed(Vehicle $vehicle): void
    {
        try {
            $data = $this->api->getVehicleSpeedData($vehicle->dsco_vehicle_id);
            if (empty($data)) {
                return;
            }

            VehicleSpeedLog::insert([[
                'time'       => now(),
                'vehicle_id' => $vehicle->id,
                'speed'      => $data['lastSpeed'] ?? null,
                'max_speed'  => $data['maxSpeed'] ?? null,
                'avg_speed'  => $data['avgSpeed'] ?? null,
                'raw'        => json_encode($data),
            ]]);
        } catch (\Throwable $e) {
            Log::warning("[Safee:{$this->provider}] Speed data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleWeight(Vehicle $vehicle): void
    {
        try {
            $data = $this->api->getVehicleWeightData($vehicle->dsco_vehicle_id);
            if (empty($data)) {
                return;
            }

            VehicleWeightLog::insert([[
                'time'       => now(),
                'vehicle_id' => $vehicle->id,
                'weight'     => $data['loadWeight'] ?? ($data['totalWeight'] ?? null),
                'raw'        => json_encode($data),
            ]]);
        } catch (\Throwable $e) {
            Log::warning("[Safee:{$this->provider}] Weight data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Backfill — position history from api/v2/vehicle/positions
    // -------------------------------------------------------------------------

    private function backfillPositions(): void
    {
        $days      = (int) env('SAFEE_BACKFILL_DAYS', env('DSCO_BACKFILL_DAYS', 7));
        $startDate = (float) now()->subDays($days)->timestamp;
        $endDate   = (float) now()->timestamp;

        $this->info("[Safee:{$this->provider}] Backfilling position history for the last {$days} days...");

        $vehicles = Vehicle::active()->where('provider', $this->provider)->get();

        foreach ($vehicles as $vehicle) {
            $this->backfillVehiclePositions($vehicle, $startDate, $endDate);
        }

        $this->info("[Safee:{$this->provider}] Position backfill complete.");
    }

    private function backfillVehiclePositions(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        $existingTimestamps = VehiclePosition::where('vehicle_id', $vehicle->id)
            ->whereBetween('time', [
                Carbon::createFromTimestamp($startDate),
                Carbon::createFromTimestamp($endDate),
            ])
            ->pluck('time')
            ->map(fn ($t) => (int) Carbon::parse($t)->timestamp)
            ->flip()
            ->all();

        $totalInserted = 0;
        $dayStart      = $startDate;

        while ($dayStart < $endDate) {
            $dayEnd = min($dayStart + 86400, $endDate);

            try {
                $rawPositions = $this->api->getVehiclePositions($vehicle->dsco_vehicle_id, $dayStart, $dayEnd, 120);
            } catch (\Throwable $e) {
                Log::warning("[Safee:{$this->provider}] Position backfill chunk failed for vehicle {$vehicle->dsco_vehicle_id} " .
                    "(day " . date('Y-m-d', (int) $dayStart) . "): " . $e->getMessage());
                $dayStart = $dayEnd;
                continue;
            }

            if (empty($rawPositions)) {
                $dayStart = $dayEnd;
                continue;
            }

            $inserts = [];
            foreach ($rawPositions as $pos) {
                $posTimestamp = (int) ($pos['date'] ?? 0);
                if ($posTimestamp === 0 || isset($existingTimestamps[$posTimestamp])) {
                    continue;
                }

                $inserts[] = [
                    'time'            => Carbon::createFromTimestamp($posTimestamp),
                    'vehicle_id'      => $vehicle->id,
                    'driver_id'       => null,
                    'lat'             => $pos['position']['lat'] ?? ($pos['lat'] ?? 0),
                    'lon'             => $pos['position']['lon'] ?? ($pos['lon'] ?? 0),
                    'alt'             => $pos['position']['alt'] ?? ($pos['alt'] ?? 0),
                    'speed'           => $pos['speed'] ?? 0,
                    'heading'         => $pos['heading'] ?? 0,
                    'ignition_status' => $pos['status'] ?? ($pos['event']['code'] ?? null),
                    'odometer'        => null,
                    'dsco_event_id'   => $pos['event']['id'] ?? null,
                    'event_name'      => $pos['event']['name'] ?? null,
                    'event_code'      => $pos['event']['code'] ?? null,
                ];

                $existingTimestamps[$posTimestamp] = true;
            }

            if (! empty($inserts)) {
                foreach (array_chunk($inserts, 500) as $chunk) {
                    VehiclePosition::insert($chunk);
                }
                $totalInserted += count($inserts);
            }

            $dayStart = $dayEnd;
        }

        if ($totalInserted === 0) {
            $this->line("[Safee:{$this->provider}] No new positions for {$vehicle->plate_no} (already up-to-date).");
        } else {
            $this->line("[Safee:{$this->provider}] Backfilled {$totalInserted} positions for {$vehicle->plate_no}.");
        }
    }
}
