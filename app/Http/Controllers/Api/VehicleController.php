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

        // Get the most recent position row for each vehicle
        $positions = VehiclePosition::whereIn('vehicle_id', $vehicleIds)
            ->select('vehicle_id', 'lat', 'lon', 'alt', 'speed', 'heading',
                     'ignition_status', 'odometer', 'event_name', 'time', 'driver_id')
            ->orderByDesc('time')
            ->get()
            ->unique('vehicle_id') // keep only the latest per vehicle
            ->keyBy('vehicle_id');

        $vehicles = $request->user()->vehicleQuery()
            ->with('currentDriver')
            ->get()
            ->map(function ($vehicle) use ($positions) {
                $pos = $positions->get($vehicle->id);
                return [
                    'id'             => $vehicle->id,
                    'dsco_id'        => $vehicle->dsco_vehicle_id,
                    'plate_no'       => $vehicle->plate_no,
                    'type'           => $vehicle->type,
                    'status'         => $vehicle->status,
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

        return response()->json([
            ...$this->formatVehicle($vehicle),
            'latest_position' => $latestPosition,
        ]);
    }

    /**
     * GET /api/vehicles/{id}/positions?from=ISO&to=ISO&limit=500
     * Position history for map playback / path drawing.
     */
    public function positions(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request, $id);

        $query = VehiclePosition::where('vehicle_id', $id)
            ->orderByDesc('time');

        if ($request->from) {
            $query->where('time', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('time', '<=', $request->to);
        }

        $positions = $query->limit($request->integer('limit', 500))->get();

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
