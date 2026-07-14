<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeofenceController extends Controller
{
    /**
     * GET /api/geofences
     * All active geofences — used to render polygon overlays on Google Maps.
     */
    public function index(Request $request): JsonResponse
    {
        // Only return zones for providers the user actually has vehicles in.
        // Alrakeen currently has 0 geofences, so this returns []; retired DSCO
        // zones are never shown.
        $providers = $request->user()->vehicleQuery()->distinct()->pluck('provider');

        $geofences = Geofence::active()
            ->whereIn('provider', $providers)
            ->get()
            ->map(fn ($g) => [
                'id'          => $g->id,
                'dsco_id'     => $g->dsco_geofence_id,
                'name'        => $g->name,
                'description' => $g->description,
                'points'      => $g->points_json, // [{lat, lon, alt}, ...]
            ]);

        return response()->json($geofences);
    }
}
