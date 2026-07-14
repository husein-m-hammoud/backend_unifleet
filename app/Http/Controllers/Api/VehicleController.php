<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehiclePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /**
     * GET /api/vehicles
     * All vehicles the user has access to.
     */
    public function index(Request $request): JsonResponse
    {
        $vehicles = $request->user()->vehicleQuery()
            ->with('currentDriver')
            ->get()
            ->map(fn ($v) => $this->formatVehicle($v));

        return response()->json($vehicles);
    }

    /**
     * GET /api/vehicles/live
     * Latest position for each of the user's vehicles — used by the map.
     */
    public function live(Request $request): JsonResponse
    {
        $user       = $request->user();
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        // Get the most recent VALID position (lat != 0) per vehicle using DISTINCT ON.
        $positions = VehiclePosition::whereIn('vehicle_id', $vehicleIds)
            ->where('lat', '!=', 0)
            ->select(\DB::raw('DISTINCT ON (vehicle_id) vehicle_id'),
                     'lat', 'lon', 'alt', 'speed', 'heading',
                     'ignition_status', 'odometer', 'event_name', 'time', 'driver_id')
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get()
            ->keyBy('vehicle_id');

        // Map each vehicle to its site (keyed by provider + Safee site id) so the
        // frontend can offer a Sites filter.
        $providers = $request->user()->vehicleQuery()->distinct()->pluck('provider');
        $siteMap   = \App\Models\Site::whereIn('provider', $providers)
            ->get()
            ->keyBy(fn ($s) => $s->provider . '|' . $s->dsco_site_id);

        $vehicles = $request->user()->vehicleQuery()
            ->with('currentDriver')
            ->get()
            ->map(function ($vehicle) use ($positions, $siteMap) {
                $pos  = $positions->get($vehicle->id);
                $site = $siteMap->get($vehicle->provider . '|' . $vehicle->dsco_site_id);
                return [
                    'id'             => $vehicle->id,
                    'dsco_id'        => $vehicle->dsco_vehicle_id,
                    'plate_no'       => $vehicle->plate_no,
                    'type'           => $vehicle->type,
                    'status'         => $vehicle->status,
                    'site'           => $site
                        ? ['id' => $site->id, 'name' => $site->name]
                        : null,
                    'driver'         => $vehicle->currentDriver
                        ? ['id' => $vehicle->currentDriver->id, 'name' => $vehicle->currentDriver->name]
                        : null,
                    'position'       => $pos ? [
                        'lat'             => $pos->lat,
                        'lon'             => $pos->lon,
                        'alt'             => $pos->alt,
                        'speed'           => $pos->speed,
                        'heading'         => $pos->heading,
                        'ignition_status' => $pos->ignition_status,
                        'odometer'        => $pos->odometer,
                        'event'           => $pos->event_name,
                        'recorded_at'     => $pos->time,
                    ] : null,
                ];
            });

        return response()->json($vehicles);
    }

    /**
     * GET /api/vehicles/fuel
     * Latest fuel snapshot per vehicle — fleet fuel overview.
     */
    public function fuelOverview(Request $request): JsonResponse
    {
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        // Latest fuel log per vehicle (DISTINCT ON)
        $fuelLogs = \App\Models\VehicleFuelLog::whereIn('vehicle_id', $vehicleIds)
            ->select(\DB::raw('DISTINCT ON (vehicle_id) vehicle_id'),
                     'time', 'fuel_liters', 'fuel_pct', 'total_fuel_used',
                     'total_idle_fuel_used', 'fuel_consumption_per_100km',
                     'range_km', 'fuel_low_indicator')
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get()
            ->keyBy('vehicle_id');

        $vehicles = $request->user()->vehicleQuery()
            ->with('currentDriver')
            ->get()
            ->map(function ($vehicle) use ($fuelLogs) {
                $fuel = $fuelLogs->get($vehicle->id);
                return [
                    'id'       => $vehicle->id,
                    'plate_no' => $vehicle->plate_no,
                    'type'     => $vehicle->type,
                    'status'   => $vehicle->status,
                    'driver'   => $vehicle->currentDriver
                        ? ['id' => $vehicle->currentDriver->id, 'name' => $vehicle->currentDriver->name]
                        : null,
                    'fuel' => $fuel ? [
                        'time'                       => $fuel->time,
                        'fuel_liters'                => (float) $fuel->fuel_liters,
                        'fuel_pct'                   => (float) $fuel->fuel_pct,
                        'total_fuel_used'            => (float) $fuel->total_fuel_used,
                        'total_idle_fuel_used'       => (float) $fuel->total_idle_fuel_used,
                        'fuel_consumption_per_100km' => (float) $fuel->fuel_consumption_per_100km,
                        'range_km'                   => (float) $fuel->range_km,
                        'fuel_low_indicator'         => (bool) $fuel->fuel_low_indicator,
                    ] : null,
                ];
            });

        return response()->json($vehicles);
    }

    /**
     * GET /api/vehicles/{id}
     * Single vehicle detail with latest state.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $vehicle = $request->user()->vehicleQuery()
            ->with('currentDriver')
            ->where('id', $id)
            ->firstOrFail();

        $latestPosition = VehiclePosition::where('vehicle_id', $vehicle->id)
            ->orderByDesc('time')
            ->first();

        $site = $vehicle->dsco_site_id
            ? \App\Models\Site::where('provider', $vehicle->provider)
                ->where('dsco_site_id', $vehicle->dsco_site_id)
                ->first()
            : null;

        return response()->json([
            ...$this->formatVehicle($vehicle),
            'site'            => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'latest_position' => $latestPosition,
        ]);
    }

    /**
     * GET /api/vehicles/{id}/positions?from=ISO&to=ISO&limit=1440
     * Position history for path drawing — returned chronologically (ASC).
     *
     * Default window: last 24 h.
     * Default limit : 1440 (one point every 2 min × 24 h, with headroom).
     */
    public function positions(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request, $id);

        $from = $request->from ?? now()->subDay()->toISOString();
        $to   = $request->to   ?? now()->toISOString();

        $positions = VehiclePosition::where('vehicle_id', $id)
            ->where('time', '>=', $from)
            ->where('time', '<=', $to)
            ->where('lat', '!=', 0)                           // Skip GPS-less records
            ->orderBy('time')                                  // ASC — chronological for path
            ->limit($request->integer('limit', 2000))
            ->get(['time', 'lat', 'lon', 'speed', 'heading', 'ignition_status']);

        return response()->json($positions);
    }

    /**
     * GET /api/vehicles/{id}/trips?from=ISO&to=ISO
     */
    public function trips(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request, $id);

        $query = \App\Models\Trip::where('vehicle_id', $id)
            ->with('driver')
            ->orderByDesc('start_time');

        if ($request->from) {
            $query->where('start_time', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('start_time', '<=', $request->to);
        }

        return response()->json($query->limit(200)->get());
    }

    /**
     * GET /api/vehicles/{id}/trips/{tripId}/path
     * Full GPS trace of a single trip, fetched live from Safee's vehicle/trip/path
     * endpoint (higher resolution than our deduped live positions). Note: Safee
     * only retains the path for recent trips — older trips may return few/no points.
     */
    public function tripPath(Request $request, int $id, int $tripId): JsonResponse
    {
        $this->ensureAccess($request, $id);

        $trip = \App\Models\Trip::where('id', $tripId)
            ->where('vehicle_id', $id)
            ->firstOrFail();

        $points = (new \App\Services\SafeeApiService($trip->provider))
            ->getTripPath((int) $trip->dsco_trip_id);

        $path = collect($points)
            ->map(function ($p) {
                $loc = $p['location'] ?? [];
                return [
                    'lat'   => isset($loc['lat']) ? (float) $loc['lat'] : null,
                    'lon'   => isset($loc['lon']) ? (float) $loc['lon'] : null,
                    'speed' => isset($p['speed']) ? (float) $p['speed'] : 0.0,
                    'time'  => isset($p['time'])
                        ? \Illuminate\Support\Carbon::createFromTimestamp((float) $p['time'])->toISOString()
                        : null,
                ];
            })
            ->filter(fn ($p) => $p['lat'] !== null && $p['lon'] !== null && ! ($p['lat'] == 0.0 && $p['lon'] == 0.0))
            ->values();

        return response()->json($path);
    }

    /**
     * GET /api/vehicles/{id}/fuel?from=ISO&to=ISO
     */
    public function fuel(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request, $id);

        $query = \App\Models\VehicleFuelLog::where('vehicle_id', $id)
            ->orderByDesc('time');

        if ($request->from) {
            $query->where('time', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('time', '<=', $request->to);
        }

        return response()->json($query->limit(500)->get());
    }

    // -------------------------------------------------------------------------

    private function formatVehicle(Vehicle $v): array
    {
        return [
            'id'            => $v->id,
            'dsco_id'       => $v->dsco_vehicle_id,
            'plate_no'      => $v->plate_no,
            'type'          => $v->type,
            'make'          => $v->vehicle_make,
            'model'         => $v->vehicle_model,
            'status'        => $v->status,
            'device_imei'   => $v->device_imei,
            'device_type'   => $v->device_type,
            'last_trip'     => $v->dsco_last_trip_date,
            'driver'        => $v->currentDriver
                ? ['id' => $v->currentDriver->id, 'name' => $v->currentDriver->name]
                : null,
        ];
    }

    private function ensureAccess(Request $request, int $vehicleId): void
    {
        $request->user()->vehicleQuery()
            ->where('id', $vehicleId)
            ->firstOrFail();
    }
}
