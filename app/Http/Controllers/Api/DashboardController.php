<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehiclePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard
     * Summary stats for the user's fleet — used by the main dashboard page.
     */
    public function index(Request $request): JsonResponse
    {
        $user       = $request->user();
        $vehicleIds = $user->vehicleQuery()->pluck('id');
        $today      = Carbon::today();

        // Live status counts — one row per vehicle (latest valid GPS), done at DB level
        $latestPositions = VehiclePosition::whereIn('vehicle_id', $vehicleIds)
            ->where('lat', '!=', 0)
            ->select(\DB::raw('DISTINCT ON (vehicle_id) vehicle_id'), 'ignition_status', 'speed', 'time')
            ->orderBy('vehicle_id')
            ->orderByDesc('time')
            ->get();

        $movingCount  = $latestPositions->where('speed', '>', 0)->count();
        $idlingCount  = $latestPositions->where('ignition_status', 'IgnitionOn')
                            ->where('speed', 0)->count();
        $offCount     = $latestPositions->where('ignition_status', 'IgnitionOff')->count();

        // Today's trips
        $todayTrips = Trip::whereIn('vehicle_id', $vehicleIds)
            ->where('start_time', '>=', $today)
            ->count();

        $todayDistance = Trip::whereIn('vehicle_id', $vehicleIds)
            ->where('start_time', '>=', $today)
            ->sum('distance'); // meters

        // Recent unresolved alerts
        $openAlerts = Alert::whereIn('vehicle_id', $vehicleIds)
            ->whereNull('resolved_at')
            ->count();

        // Active vs disabled vehicles
        $activeVehicles   = $user->vehicleQuery()->where('status', 'active')->count();
        $disabledVehicles = $user->vehicleQuery()->where('status', 'disabled')->count();

        return response()->json([
            'vehicles' => [
                'total'    => $vehicleIds->count(),
                'active'   => $activeVehicles,
                'disabled' => $disabledVehicles,
                'moving'   => $movingCount,
                'idling'   => $idlingCount,
                'off'      => $offCount,
            ],
            'today' => [
                'trips'         => $todayTrips,
                'distance_km'   => round($todayDistance / 1000, 2),
            ],
            'alerts' => [
                'open' => $openAlerts,
            ],
            'last_updated' => now(),
        ]);
    }
}
