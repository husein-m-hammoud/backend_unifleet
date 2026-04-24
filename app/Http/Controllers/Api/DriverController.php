<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * GET /api/drivers
     * Drivers linked to the user's accessible vehicles (current + history).
     */
    public function index(Request $request): JsonResponse
    {
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        // Drivers currently assigned to user's vehicles
        $currentDriverIds = Vehicle::whereIn('id', $vehicleIds)
            ->whereNotNull('current_driver_id')
            ->pluck('current_driver_id');

        // Drivers from assignment history for user's vehicles
        $historicDriverIds = \App\Models\VehicleDriverAssignment::whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('driver_id')
            ->pluck('driver_id');

        $driverIds = $currentDriverIds->merge($historicDriverIds)->unique();

        $drivers = Driver::whereIn('id', $driverIds)
            ->get()
            ->map(fn ($d) => $this->formatDriver($d));

        return response()->json($drivers);
    }

    /**
     * GET /api/drivers/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        // Ensure this driver is linked to the user's vehicles
        $hasAccess = \App\Models\VehicleDriverAssignment::whereIn('vehicle_id', $vehicleIds)
            ->where('driver_id', $id)
            ->exists();

        $currentAccess = Vehicle::whereIn('id', $vehicleIds)
            ->where('current_driver_id', $id)
            ->exists();

        abort_if(! $hasAccess && ! $currentAccess, 403);

        $driver = Driver::findOrFail($id);

        // Current vehicle
        $currentVehicle = Vehicle::whereIn('id', $vehicleIds)
            ->where('current_driver_id', $id)
            ->first();

        // Trip history
        $recentTrips = \App\Models\Trip::where('driver_id', $id)
            ->orderByDesc('start_time')
            ->limit(20)
            ->get();

        return response()->json([
            ...$this->formatDriver($driver),
            'current_vehicle' => $currentVehicle
                ? ['id' => $currentVehicle->id, 'plate_no' => $currentVehicle->plate_no]
                : null,
            'recent_trips' => $recentTrips,
        ]);
    }

    /**
     * GET /api/drivers/{id}/trips?from=ISO&to=ISO
     */
    public function trips(Request $request, int $id): JsonResponse
    {
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        $query = \App\Models\Trip::where('driver_id', $id)
            ->whereIn('vehicle_id', $vehicleIds)
            ->with('vehicle')
            ->orderByDesc('start_time');

        if ($request->from) {
            $query->where('start_time', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('start_time', '<=', $request->to);
        }

        return response()->json($query->limit(200)->get());
    }

    private function formatDriver(Driver $d): array
    {
        return [
            'id'               => $d->id,
            'dsco_id'          => $d->dsco_driver_id,
            'name'             => $d->name,
            'mobile'           => $d->mobile,
            'gender'           => $d->gender,
            'license_status'   => $d->license_status,
            'residency_status' => $d->residency_status,
            'status'           => $d->status,
        ];
    }
}
