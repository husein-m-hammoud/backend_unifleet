<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * GET /api/alerts?from=ISO&to=ISO&type=over_speed&vehicle_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $vehicleIds = $request->user()->vehicleQuery()->pluck('id');

        $query = Alert::whereIn('vehicle_id', $vehicleIds)
            ->with(['vehicle', 'driver'])
            ->orderByDesc('triggered_at');

        if ($request->from) {
            $query->where('triggered_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('triggered_at', '<=', $request->to);
        }
        if ($request->type) {
            $query->where('type', $request->type);
        }
        if ($request->vehicle_id) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        return response()->json($query->limit(200)->get());
    }
}
