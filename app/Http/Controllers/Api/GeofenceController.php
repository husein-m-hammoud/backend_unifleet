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
        $geofences = Geofence::active()
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
