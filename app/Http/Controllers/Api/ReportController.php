<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    /** Period presets keyed by report type. */
    private const TYPES = ['daily', 'weekly', 'monthly', 'driver_safety'];

    /**
     * GET /api/reports — the caller's saved reports (newest first).
     */
    public function index(Request $request): JsonResponse
    {
        $reports = Report::where('created_by', $request->user()->id)
            ->latest('generated_at')
            ->limit(100)
            ->get()
            ->map(fn (Report $r) => $this->summary($r));

        return response()->json(['data' => $reports]);
    }

    /**
     * POST /api/reports — generate + persist a snapshot, return the full JSON.
     * The snapshot is computed against the caller's scoped vehicles so it can be
     * re-exported later without recomputation (and without leaking scope).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'  => ['required', 'string', 'in:' . implode(',', self::TYPES)],
            'from'  => ['nullable', 'date'],
            'to'    => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        [$from, $to] = $this->range($data);

        $vehicleIds    = $request->user()->vehicleQuery()->pluck('id');
        $payload       = $this->analytics->insights($vehicleIds, $from, $to, $vehicleIds->count());
        $payload['type'] = $data['type'];

        $report = Report::create([
            'created_by'   => $request->user()->id,
            'type'         => $data['type'],
            'title'        => $data['title'] ?? $this->defaultTitle($data['type'], $from, $to),
            'generated_at' => now(),
            'period_from'  => $from,
            'period_to'    => $to,
            'content'      => $payload,
        ]);

        return response()->json([
            'report'  => $this->summary($report),
            'content' => $payload,
        ], 201);
    }

    /**
     * GET /api/reports/{id} — a saved snapshot's full JSON (owner only).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $report = Report::where('created_by', $request->user()->id)->findOrFail($id);

        return response()->json([
            'report'  => $this->summary($report),
            'content' => $report->content,
        ]);
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    private function summary(Report $r): array
    {
        return [
            'id'           => $r->id,
            'type'         => $r->type,
            'title'        => $r->title,
            'generated_at' => $r->generated_at?->toIso8601String(),
            'period_from'  => $r->period_from?->toIso8601String(),
            'period_to'    => $r->period_to?->toIso8601String(),
        ];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(array $data): array
    {
        if (! empty($data['from']) && ! empty($data['to'])) {
            return [Carbon::parse($data['from']), Carbon::parse($data['to'])];
        }

        $to = Carbon::now();

        $from = match ($data['type']) {
            'daily'   => (clone $to)->startOfDay(),
            'monthly' => (clone $to)->subMonth(),
            default   => (clone $to)->subWeek(), // weekly + driver_safety
        };

        return [$from, $to];
    }

    private function defaultTitle(string $type, Carbon $from, Carbon $to): string
    {
        $label = match ($type) {
            'daily'         => 'Daily Fleet Summary',
            'weekly'        => 'Weekly Fleet Report',
            'monthly'       => 'Monthly Performance',
            'driver_safety' => 'Safety Report',
            default         => 'Fleet Report',
        };

        return $label . ' — ' . $from->format('M j') . '–' . $to->format('M j, Y');
    }
}
