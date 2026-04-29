<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\Site;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use App\Models\VehicleFuelLog;
use App\Models\VehiclePosition;
use App\Models\VehicleSpeedLog;
use App\Models\VehicleWeightLog;
use App\Services\DscoApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DscoPollCommand extends Command
{
    protected $signature   = 'dsco:poll
                                {--force    : Ignore interval check and run immediately}
                                {--backfill : Fetch full position history from DSCO and fill vehicle_positions}';
    protected $description = 'Poll DSCO API and sync data into the database';

    // Cache keys
    private const LAST_RUN_KEY  = 'dsco_last_poll_ran_at';
    private const LAST_SYNC_KEY = 'dsco_last_sync_timestamp'; // float unix for API date filters

    public function __construct(private DscoApiService $dsco)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->shouldRun()) {
            return self::SUCCESS;
        }

        $this->info('[DSCO] Poll started at ' . now()->toDateTimeString());

        try {
            $this->syncSites();
            $this->syncCategories();
            $this->syncGeofences();
            $this->syncDrivers();
            $this->syncVehicles();
            $this->syncLiveState();
            $this->syncTripsAndTelemetry();

            if ($this->option('backfill')) {
                $this->backfillPositions();
            }
        } catch (\Throwable $e) {
            Log::error('[DSCO] Poll failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->error('[DSCO] Poll failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Store timestamps for next run
        Cache::put(self::LAST_RUN_KEY, now()->timestamp, now()->addHours(24));
        Cache::put(self::LAST_SYNC_KEY, microtime(true), now()->addHours(24));

        $this->info('[DSCO] Poll completed at ' . now()->toDateTimeString());
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Interval guard
    // -------------------------------------------------------------------------

    private function shouldRun(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $intervalMinutes = (int) env('DSCO_POLL_INTERVAL_MINUTES', 2);
        $lastRan         = Cache::get(self::LAST_RUN_KEY);

        if ($lastRan === null) {
            return true; // First run ever
        }

        return (now()->timestamp - $lastRan) >= ($intervalMinutes * 60);
    }

    // -------------------------------------------------------------------------
    // "Since when" helper for date-filtered API calls
    // -------------------------------------------------------------------------

    private function lookbackTimestamp(): float
    {
        $cached = Cache::get(self::LAST_SYNC_KEY);

        if ($cached !== null) {
            return (float) $cached;
        }

        // Fallback: go back N hours defined in .env
        $hours = (int) env('DSCO_LOOKBACK_HOURS', 24);
        return (float) now()->subHours($hours)->timestamp;
    }

    // -------------------------------------------------------------------------
    // Step 1 — Sites
    // -------------------------------------------------------------------------

    private function syncSites(): void
    {
        $this->line('[DSCO] Syncing sites...');
        $rows = $this->dsco->getSites();
        $now  = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Site::updateOrCreate(
                ['dsco_site_id' => $row['id']],
                [
                    'name'              => $row['name'],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        // Disable sites not returned by DSCO
        if (! empty($seenIds)) {
            Site::whereNotIn('dsco_site_id', $seenIds)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $this->line('[DSCO] Sites synced: ' . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 2 — Categories
    // -------------------------------------------------------------------------

    private function syncCategories(): void
    {
        $this->line('[DSCO] Syncing categories...');
        $rows    = $this->dsco->getCategories();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Category::updateOrCreate(
                ['dsco_category_id' => $row['id']],
                [
                    'dsco_site_id'      => $row['siteId'],
                    'dsco_parent_id'    => $row['parentId'] ?? null,
                    'name'              => $row['name'],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        if (! empty($seenIds)) {
            Category::whereNotIn('dsco_category_id', $seenIds)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $this->line('[DSCO] Categories synced: ' . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 3 — Geofences
    // -------------------------------------------------------------------------

    private function syncGeofences(): void
    {
        $this->line('[DSCO] Syncing geofences...');
        $rows    = $this->dsco->getGeofences();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Geofence::updateOrCreate(
                ['dsco_geofence_id' => $row['id']],
                [
                    'dsco_uuid'         => $row['uuid'] ?? null,
                    'name'              => $row['name'],
                    'code'              => $row['code'] ?? null,
                    'dsco_company_id'   => $row['companyId'] ?? null,
                    'description'       => $row['description'] ?? null,
                    'points_json'       => $row['points'],
                    'status'            => 'active',
                    'dsco_last_seen_at' => $now,
                ]
            );
        }

        if (! empty($seenIds)) {
            Geofence::whereNotIn('dsco_geofence_id', $seenIds)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $this->line('[DSCO] Geofences synced: ' . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 4 — Drivers
    // -------------------------------------------------------------------------

    private function syncDrivers(): void
    {
        $this->line('[DSCO] Syncing drivers...');
        $rows    = $this->dsco->getDriverListInfo();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];
            Driver::updateOrCreate(
                ['dsco_driver_id' => $row['id']],
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
                    'dsco_created_at'   => isset($row['createdAt'])
                        ? Carbon::createFromTimestamp($row['createdAt'])
                        : null,
                    'dsco_updated_at'   => isset($row['updatedAt'])
                        ? Carbon::createFromTimestamp($row['updatedAt'])
                        : null,
                ]
            );
        }

        // Disable drivers not returned by DSCO
        if (! empty($seenIds)) {
            Driver::whereNotIn('dsco_driver_id', $seenIds)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $this->line('[DSCO] Drivers synced: ' . count($seenIds));
    }

    // -------------------------------------------------------------------------
    // Step 5 — Vehicles
    // -------------------------------------------------------------------------

    private function syncVehicles(): void
    {
        $this->line('[DSCO] Syncing vehicles...');
        $rows    = $this->dsco->getVehicleListInfo();
        $now     = now();
        $seenIds = [];

        foreach ($rows as $row) {
            $seenIds[] = $row['id'];

            // Resolve local driver FK
            $localDriverId = null;
            if (! empty($row['driver']['id'])) {
                $localDriver   = Driver::where('dsco_driver_id', $row['driver']['id'])->first();
                $localDriverId = $localDriver?->id;
            }

            $vehicle = Vehicle::updateOrCreate(
                ['dsco_vehicle_id' => $row['id']],
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
                    'dsco_created_at'          => isset($row['createdAt'])
                        ? Carbon::createFromTimestamp($row['createdAt'])
                        : null,
                    'dsco_first_trip_date'     => isset($row['firstTripDate'])
                        ? Carbon::createFromTimestamp((float) $row['firstTripDate'])
                        : null,
                    'dsco_last_trip_date'      => isset($row['lastTripDate'])
                        ? Carbon::createFromTimestamp((float) $row['lastTripDate'])
                        : null,
                    'dsco_device_id'           => $row['device']['id'] ?? null,
                    'device_sim'               => $row['device']['sim'] ?? null,
                    'device_imei'              => $row['device']['imei'] ?? null,
                    'device_type'              => $row['device']['type'] ?? null,
                    'device_serial'            => $row['device']['serial'] ?? null,
                    'device_installation_date' => isset($row['deviceInstallationDate'])
                        ? Carbon::createFromTimestamp((float) $row['deviceInstallationDate'])
                        : null,
                    'status'                   => 'active',
                    'dsco_last_seen_at'        => $now,
                ]
            );

            // Track driver assignment changes
            $this->trackDriverAssignment($vehicle, $localDriverId);
        }

        // Disable vehicles not returned by DSCO this cycle
        if (! empty($seenIds)) {
            Vehicle::whereNotIn('dsco_vehicle_id', $seenIds)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $this->line('[DSCO] Vehicles synced: ' . count($seenIds));
    }

    /**
     * If the driver on a vehicle changed, close the previous assignment record
     * and open a new one.
     */
    private function trackDriverAssignment(Vehicle $vehicle, ?int $newDriverId): void
    {
        $openAssignment = VehicleDriverAssignment::where('vehicle_id', $vehicle->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        $previousDriverId = $openAssignment?->driver_id;

        if ($previousDriverId === $newDriverId) {
            // No change — nothing to do
            return;
        }

        // Close the previous open assignment
        if ($openAssignment) {
            $openAssignment->update(['ended_at' => now()]);
        }

        // Open a new assignment (even if newDriverId is null = unassigned)
        VehicleDriverAssignment::create([
            'vehicle_id' => $vehicle->id,
            'driver_id'  => $newDriverId,
            'started_at' => now(),
            'ended_at'   => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 6 — Live state (positions)
    // -------------------------------------------------------------------------

    private function syncLiveState(): void
    {
        $this->line('[DSCO] Fetching live vehicle states...');

        $activeVehicles = Vehicle::active()->get();

        if ($activeVehicles->isEmpty()) {
            $this->line('[DSCO] No active vehicles — skipping live state.');
            return;
        }

        // Build dsco_vehicle_id => local_id map for FK resolution
        $vehicleMap = $activeVehicles->keyBy('dsco_vehicle_id');
        $dscoIds    = $vehicleMap->keys()->all();

        // Chunk to avoid huge request bodies
        $chunks = array_chunk($dscoIds, 50);
        $now    = now();

        foreach ($chunks as $chunk) {
            $states = $this->dsco->getVehicleLastState($chunk);

            $inserts = [];
            foreach ($states as $state) {
                $vehicle = $vehicleMap->get($state['id']);
                if (! $vehicle) {
                    continue;
                }

                // Resolve local driver FK from the live state driver
                $driverId = null;
                if (! empty($state['driver']['id'])) {
                    $driverId = Driver::where('dsco_driver_id', $state['driver']['id'])
                        ->value('id');
                }

                $lat = $state['position']['lat'] ?? 0;
                $lon = $state['position']['lon'] ?? 0;

                // Skip positions with no GPS fix — don't pollute the table with (0,0) rows
                if ((float) $lat === 0.0 && (float) $lon === 0.0) {
                    continue;
                }

                $inserts[] = [
                    'time'            => isset($state['date'])
                        ? Carbon::createFromTimestamp($state['date'])
                        : $now,
                    'vehicle_id'      => $vehicle->id,
                    'driver_id'       => $driverId,
                    'lat'             => $lat,
                    'lon'             => $lon,
                    'alt'             => $state['position']['alt'] ?? 0,
                    'speed'           => $state['speed'] ?? 0,
                    'heading'         => $state['heading'] ?? 0,
                    'ignition_status' => $state['status'] ?? null,
                    'odometer'        => isset($state['odometer'])
                        ? (float) $state['odometer']
                        : null,
                    'dsco_event_id'   => $state['event']['id'] ?? null,
                    'event_name'      => $state['event']['name'] ?? null,
                    'event_code'      => $state['event']['code'] ?? null,
                ];
            }

            if (! empty($inserts)) {
                VehiclePosition::insert($inserts);
            }
        }

        $this->line('[DSCO] Live states stored for ' . $activeVehicles->count() . ' vehicles.');
    }

    // -------------------------------------------------------------------------
    // Step 7 — Trips + per-vehicle telemetry (fuel, speed, weight)
    // -------------------------------------------------------------------------

    private function syncTripsAndTelemetry(): void
    {
        $startDate = $this->lookbackTimestamp();
        $endDate   = microtime(true);

        $activeVehicles = Vehicle::active()->get();

        foreach ($activeVehicles as $vehicle) {
            $this->syncVehicleTrips($vehicle, $startDate, $endDate);
            $this->syncVehicleFuel($vehicle, $startDate, $endDate);
            $this->syncVehicleSpeed($vehicle, $startDate, $endDate);
            $this->syncVehicleWeight($vehicle, $startDate, $endDate);
        }
    }

    private function syncVehicleTrips(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        try {
            $trips = $this->dsco->getVehicleTrips($vehicle->dsco_vehicle_id, $startDate, $endDate);

            foreach ($trips as $t) {
                // Resolve driver FK
                $driverId = null;
                if (! empty($t['driver']['id'])) {
                    $driverId = Driver::where('dsco_driver_id', $t['driver']['id'])->value('id');
                }

                Trip::updateOrCreate(
                    ['dsco_trip_id' => $t['id']],
                    [
                        'vehicle_id'   => $vehicle->id,
                        'driver_id'    => $driverId,
                        'start_time'   => Carbon::createFromTimestamp($t['startTime']),
                        'end_time'     => isset($t['endTime'])
                            ? Carbon::createFromTimestamp($t['endTime'])
                            : null,
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
            Log::warning("[DSCO] Trips failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleFuel(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        try {
            $data = $this->dsco->getVehicleFuelData($vehicle->dsco_vehicle_id, $startDate, $endDate);

            if (empty($data)) {
                return;
            }

            // Normalize: API may return a single object or an array of objects
            $rows = isset($data[0]) ? $data : [$data];

            $inserts = [];
            foreach ($rows as $row) {
                $inserts[] = [
                    'time'                       => now(),
                    'vehicle_id'                 => $vehicle->id,
                    'fuel_liters'                => $row['fuelLiters'] ?? null,
                    'fuel_pct'                   => $row['fuelPercentage'] ?? null,
                    'total_fuel_used'            => $row['totalFuelUsed'] ?? null,
                    'total_idle_fuel_used'       => $row['totalIdleFuelUsed'] ?? null,
                    'fuel_consumption_per_100km' => $row['fuelConsumptionPerHunderedKm'] ?? null,
                    'range_km'                   => $row['rangeKm'] ?? null,
                    'fuel_low_indicator'         => $row['fuelLowIndicatorOn'] ?? null,
                ];
            }

            VehicleFuelLog::insert($inserts);
        } catch (\Throwable $e) {
            Log::warning("[DSCO] Fuel data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleSpeed(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        try {
            $data = $this->dsco->getVehicleSpeedData($vehicle->dsco_vehicle_id, $startDate, $endDate);

            if (empty($data)) {
                return;
            }

            $rows = isset($data[0]) ? $data : [$data];

            $inserts = [];
            foreach ($rows as $row) {
                $inserts[] = [
                    'time'       => now(),
                    'vehicle_id' => $vehicle->id,
                    'speed'      => $row['speed'] ?? null,
                    'max_speed'  => $row['maxSpeed'] ?? null,
                    'avg_speed'  => $row['avgSpeed'] ?? null,
                    'raw'        => json_encode($row),
                ];
            }

            VehicleSpeedLog::insert($inserts);
        } catch (\Throwable $e) {
            Log::warning("[DSCO] Speed data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    private function syncVehicleWeight(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        try {
            $data = $this->dsco->getVehicleWeightData($vehicle->dsco_vehicle_id, $startDate, $endDate);

            if (empty($data)) {
                return;
            }

            $rows = isset($data[0]) ? $data : [$data];

            $inserts = [];
            foreach ($rows as $row) {
                $inserts[] = [
                    'time'       => now(),
                    'vehicle_id' => $vehicle->id,
                    'weight'     => $row['weight'] ?? null,
                    'raw'        => json_encode($row),
                ];
            }

            VehicleWeightLog::insert($inserts);
        } catch (\Throwable $e) {
            Log::warning("[DSCO] Weight data failed for vehicle {$vehicle->dsco_vehicle_id}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Backfill — position history from DSCO api/v2/vehicle/positions
    // -------------------------------------------------------------------------

    /**
     * Fetch full GPS track history for every active vehicle and store in
     * vehicle_positions, skipping records that already exist.
     *
     * Run with:  php artisan dsco:poll --backfill [--force]
     *
     * Controlled by env:
     *   DSCO_BACKFILL_DAYS=7  (how many days of history to pull, default 7)
     */
    private function backfillPositions(): void
    {
        $days      = (int) env('DSCO_BACKFILL_DAYS', 7);
        $startDate = (float) now()->subDays($days)->timestamp;
        $endDate   = (float) now()->timestamp;

        $this->info("[DSCO] Backfilling position history for the last {$days} days...");

        $vehicles = Vehicle::active()->get();

        foreach ($vehicles as $vehicle) {
            $this->backfillVehiclePositions($vehicle, $startDate, $endDate);
        }

        $this->info('[DSCO] Position backfill complete.');
    }

    private function backfillVehiclePositions(Vehicle $vehicle, float $startDate, float $endDate): void
    {
        // Pre-load all existing timestamps in the window for O(1) dedup
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

        // Iterate one day at a time so no single DSCO request returns a huge payload
        $dayStart = $startDate;
        while ($dayStart < $endDate) {
            $dayEnd = min($dayStart + 86400, $endDate);

            try {
                $rawPositions = $this->dsco->getVehiclePositions(
                    $vehicle->dsco_vehicle_id,
                    $dayStart,
                    $dayEnd,
                    120  // 2-minute timeout per day-chunk
                );
            } catch (\Throwable $e) {
                Log::warning("[DSCO] Position backfill chunk failed for vehicle {$vehicle->dsco_vehicle_id} " .
                    "(day " . date('Y-m-d', (int) $dayStart) . "): " . $e->getMessage());
                $this->warn("[DSCO] Skipping chunk for {$vehicle->plate_no} on " .
                    date('Y-m-d', (int) $dayStart) . ": " . $e->getMessage());
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

                if ($posTimestamp === 0) {
                    continue;
                }

                if (isset($existingTimestamps[$posTimestamp])) {
                    continue;
                }

                $inserts[] = [
                    'time'            => Carbon::createFromTimestamp($posTimestamp),
                    'vehicle_id'      => $vehicle->id,
                    'driver_id'       => null,
                    'lat'             => $pos['lat'] ?? 0,
                    'lon'             => $pos['lon'] ?? 0,
                    'alt'             => $pos['alt'] ?? 0,
                    'speed'           => $pos['speed'] ?? 0,
                    'heading'         => $pos['heading'] ?? 0,
                    'ignition_status' => $pos['event']['code'] ?? null,
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
            $this->line("[DSCO] No new positions for {$vehicle->plate_no} (already up-to-date).");
        } else {
            $this->line("[DSCO] Backfilled {$totalInserted} positions for {$vehicle->plate_no}.");
        }
    }
}
