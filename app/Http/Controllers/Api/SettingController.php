<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SettingChangelog;
use App\Models\Site;
use App\Models\VehicleTypeIdleThreshold;
use App\Models\VehicleTypeSpeedLimit;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/settings
     * Returns all settings grouped by their group key.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(function ($items, $group) {
            return [
                'group'    => $group,
                'settings' => $items->map(fn($s) => $this->format($s))->values(),
            ];
        })->values();

        return response()->json($grouped);
    }

    /**
     * PUT /api/settings/{key}
     * Update a single setting and record a changelog entry.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $setting = Setting::findOrFail($key);

        $request->validate([
            'value' => ['required', 'string', 'max:1000'],
        ]);

        $newRaw = $request->input('value');

        // Type-level validation
        match ($setting->type) {
            'integer' => $request->validate(['value' => 'integer']),
            'float'   => $request->validate(['value' => 'numeric']),
            'boolean' => $request->validate(['value' => 'in:true,false,1,0']),
            default   => null,
        };

        $oldRaw = $setting->value;

        if ($oldRaw === $newRaw) {
            return response()->json($this->format($setting));
        }

        $setting->update(['value' => $newRaw]);

        SettingChangelog::create([
            'setting_key'   => $key,
            'setting_label' => $setting->label,
            'old_value'     => $oldRaw,
            'new_value'     => $newRaw,
            'user_id'       => $request->user()->id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json($this->format($setting->fresh()));
    }

    /**
     * GET /api/settings/changelog
     * GET /api/settings/{key}/changelog
     * Returns recent changelog entries, optionally filtered by setting key.
     */
    public function changelog(Request $request, ?string $key = null): JsonResponse
    {
        $query = SettingChangelog::with('user:id,name')
            ->orderByDesc('changed_at')
            ->limit(200);

        if ($key) {
            $query->where('setting_key', $key);
        }

        return response()->json(
            $query->get()->map(fn($c) => [
                'id'            => $c->id,
                'setting_key'   => $c->setting_key,
                'setting_label' => $c->setting_label,
                'old_value'     => $c->old_value,
                'new_value'     => $c->new_value,
                'changed_at'    => $c->changed_at,
                'changed_by'    => $c->user?->name ?? 'System',
                'ip_address'    => $c->ip_address,
            ])
        );
    }

    /**
     * GET /api/settings/idle-thresholds
     * Idle thresholds at every level: the global default, a per-type override for
     * every vehicle type in the fleet, and a per-zone threshold for every canonical
     * zone. Effective priority when a vehicle is inside a zone:
     * zone threshold > type override > global default.
     */
    public function idleThresholds(Request $request): JsonResponse
    {
        $default   = (int) Setting::get('idle_threshold', 10);
        $overrides = VehicleTypeIdleThreshold::pluck('idle_threshold', 'type');

        $types = $request->user()->vehicleQuery()
            ->selectRaw('type, count(*) as vehicle_count')
            ->groupBy('type')
            ->orderByDesc('vehicle_count')
            ->get()
            ->map(fn ($t) => [
                'type'           => $t->type,
                'vehicle_count'  => (int) $t->vehicle_count,
                'idle_threshold' => $overrides[$t->type] ?? null,
                'effective'      => $overrides[$t->type] ?? $default,
            ]);

        $zones = Zone::active()
            ->with('site:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'site_id', 'idle_threshold'])
            ->map(fn (Zone $z) => [
                'id'             => $z->id,
                'name'           => $z->name,
                'site_name'      => $z->site?->name,
                'idle_threshold' => $z->idle_threshold,
            ]);

        return response()->json([
            'default' => $default,
            'types'   => $types,
            'zones'   => $zones,
        ]);
    }

    /**
     * PUT /api/settings/idle-thresholds
     * Upsert per-type and/or per-zone overrides. A value of null clears the
     * override (falls back down the priority chain). Body:
     * `{ types?: { TYPE: minutes|null }, zones?: { ZONE_ID: minutes|null } }`.
     */
    public function updateIdleThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types'   => ['sometimes', 'array'],
            'types.*' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'zones'   => ['sometimes', 'array'],
            'zones.*' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        foreach ($data['types'] ?? [] as $type => $minutes) {
            if ($minutes === null) {
                VehicleTypeIdleThreshold::where('type', $type)->delete();
            } else {
                VehicleTypeIdleThreshold::updateOrCreate(
                    ['type' => $type],
                    ['idle_threshold' => $minutes],
                );
            }
        }

        foreach ($data['zones'] ?? [] as $zoneId => $minutes) {
            Zone::whereKey($zoneId)->update(['idle_threshold' => $minutes]); // null clears
        }

        return $this->idleThresholds($request);
    }

    /**
     * GET /api/settings/speed-limits
     * Over-speed limits at every level: the global default, a per-type override
     * for every vehicle type in the fleet, and a per-site limit for every
     * canonical site. Effective priority when a vehicle is inside a site:
     * site limit > type override > global default.
     */
    public function speedLimits(Request $request): JsonResponse
    {
        $default       = (int) Setting::get('speed_limit', 120);
        $typeOverrides = VehicleTypeSpeedLimit::pluck('speed_limit', 'type');

        $types = $request->user()->vehicleQuery()
            ->selectRaw('type, count(*) as vehicle_count')
            ->groupBy('type')
            ->orderByDesc('vehicle_count')
            ->get()
            ->map(fn ($t) => [
                'type'          => $t->type,
                'vehicle_count' => (int) $t->vehicle_count,
                'speed_limit'   => $typeOverrides[$t->type] ?? null,
                'effective'     => $typeOverrides[$t->type] ?? $default,
            ]);

        $sites = Site::active()
            ->orderBy('name')
            ->get(['id', 'name', 'speed_limit'])
            ->map(fn (Site $s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'speed_limit' => $s->speed_limit,
            ]);

        return response()->json([
            'default' => $default,
            'types'   => $types,
            'sites'   => $sites,
        ]);
    }

    /**
     * PUT /api/settings/speed-limits
     * Upsert per-type and/or per-site overrides. A value of null clears the
     * override (falls back down the priority chain). Body:
     * `{ types?: { TYPE: kmh|null }, sites?: { SITE_ID: kmh|null } }`.
     */
    public function updateSpeedLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types'   => ['sometimes', 'array'],
            'types.*' => ['nullable', 'integer', 'min:1', 'max:400'],
            'sites'   => ['sometimes', 'array'],
            'sites.*' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);

        foreach ($data['types'] ?? [] as $type => $kmh) {
            if ($kmh === null) {
                VehicleTypeSpeedLimit::where('type', $type)->delete();
            } else {
                VehicleTypeSpeedLimit::updateOrCreate(
                    ['type' => $type],
                    ['speed_limit' => $kmh],
                );
            }
        }

        foreach ($data['sites'] ?? [] as $siteId => $kmh) {
            Site::whereKey($siteId)->update(['speed_limit' => $kmh]); // null clears
        }

        return $this->speedLimits($request);
    }

    private function format(Setting $s): array
    {
        return [
            'key'          => $s->key,
            'value'        => $s->value,
            'casted_value' => $s->casted_value,
            'type'         => $s->type,
            'label'        => $s->label,
            'description'  => $s->description,
            'group'        => $s->group,
        ];
    }
}
