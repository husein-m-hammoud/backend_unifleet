<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    /**
     * GET /api/analytics/insights?from&to&zone&type
     *
     * The "AI Insights" payload: fleet health score, KPIs vs. previous period,
     * plain-language findings and the detail sections. Always scoped to the
     * caller's own vehicles via vehicleQuery().
     */
    public function insights(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        // Base scope = everything this user may see, then apply page filters.
        $query = $request->user()->vehicleQuery();

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('zone')) {
            $zoneId = (int) $request->query('zone');
            $query->whereHas('zones', fn ($q) => $q->where('zones.id', $zoneId));
        }

        $vehicleIds     = $query->pluck('id');
        $totalVehicles  = $vehicleIds->count();

        $payload = $this->analytics->insights($vehicleIds, $from, $to, $totalVehicles);

        return response()->json($payload);
    }

    /**
     * Resolve the [from, to] window. Defaults to the last 7 days.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))
            : (clone $to)->subDays(7);

        return [$from, $to];
    }
}
