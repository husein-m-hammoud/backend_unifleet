<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Vehicle;
use App\Models\VehicleZoneChangelog;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Management of the canonical, name-based Zone / Site layer and the
 * vehicle → zone assignment. Reads are open to any authenticated user (these
 * are config labels); writes are admin-only.
 */
class ZoneController extends Controller
{
    // ── Zones ─────────────────────────────────────────────────────────────────

    /** GET /api/zones — canonical zones with their site + provider/vehicle counts. */
    public function index(Request $request): JsonResponse
    {
        $query = Zone::with(['site', 'creator'])
            ->withCount(['geofences', 'vehicles'])
            ->orderBy('name');

        // A scoped user only sees the zones they've been granted — so map/vehicle
        // filters list just their zones, not the whole fleet's. Managers and
        // "all zones" users see everything.
        $user = $request->user();
        if (! $user->canSeeAllZones()) {
            $query->whereIn('id', $user->effectiveZoneIds() ?: [0]);
        }

        $zones = $query->get()->map(fn (Zone $z) => $this->formatZone($z));

        return response()->json($zones);
    }

    /** POST /api/zones — create a canonical zone by name (optionally under a site). Admin only. */
    public function storeZone(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $name = $this->cleanName($data['name']);
        $slug = Str::slug($name);

        // Merge on slug so a zone name is never duplicated.
        $zone = Zone::firstOrCreate(
            ['slug' => $slug],
            [
                'name'       => $name,
                'site_id'    => $data['site_id'] ?? null,
                'status'     => 'active',
                'is_open'    => false,
                'source'     => 'manual',
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json(
            $this->formatZone($zone->load(['site', 'creator'])->loadCount(['geofences', 'vehicles'])),
            $zone->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** PUT /api/zones/{id} — set a zone's site and/or status. Admin only. */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);

        $zone = Zone::findOrFail($id);

        $data = $request->validate([
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'status'  => ['sometimes', 'in:active,disabled'],
            'is_open' => ['sometimes', 'boolean'],
        ]);

        $zone->fill($data)->save();

        return response()->json($this->formatZone(
            $zone->load(['site', 'creator'])->loadCount(['geofences', 'vehicles'])
        ));
    }

    // ── Canonical sites ───────────────────────────────────────────────────────

    /** GET /api/zones/sites — canonical sites with zone counts. */
    public function sites(Request $request): JsonResponse
    {
        $query = Site::with('creator')->withCount('zones')->orderBy('name');

        // Scope to the sites a granted user can actually reach: sites granted
        // directly plus the sites of any zone they were granted.
        $user = $request->user();
        if (! $user->canSeeAllZones()) {
            $direct   = $user->grantedSites()->pluck('sites.id');
            $viaZones = Zone::whereIn('id', $user->effectiveZoneIds() ?: [0])
                ->whereNotNull('site_id')->pluck('site_id');
            $query->whereIn('id', $direct->merge($viaZones)->unique()->values() ?: [0]);
        }

        $sites = $query->get()
            ->map(fn (Site $s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'slug'       => $s->slug,
                'status'     => $s->status,
                'source'     => $s->source,
                'created_at' => $s->created_at,
                'created_by' => $s->creator?->name,
                'zone_count' => $s->zones_count,
            ]);

        return response()->json($sites);
    }

    /** POST /api/zones/sites — create a canonical site. Admin only. */
    public function storeSite(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim(preg_replace('/\s+/', ' ', $data['name']));
        $slug = Str::slug($name);

        // Merge on slug so we never create a duplicate of an existing site name.
        $site = Site::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => 'active', 'source' => 'manual', 'created_by' => $request->user()->id],
        );

        return response()->json([
            'id'     => $site->id,
            'name'   => $site->name,
            'slug'   => $site->slug,
            'status' => $site->status,
        ], $site->wasRecentlyCreated ? 201 : 200);
    }

    /** PUT /api/zones/sites/{siteId} — rename / change status. Admin only. */
    public function updateSite(Request $request, int $siteId): JsonResponse
    {
        $this->ensureAdmin($request);

        $site = Site::findOrFail($siteId);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,disabled'],
        ]);

        if (isset($data['name'])) {
            $name = trim(preg_replace('/\s+/', ' ', $data['name']));
            $site->name = $name;
            $site->slug = Str::slug($name);
        }
        if (isset($data['status'])) {
            $site->status = $data['status'];
        }
        $site->save();

        return response()->json([
            'id'     => $site->id,
            'name'   => $site->name,
            'slug'   => $site->slug,
            'status' => $site->status,
        ]);
    }

    // ── Vehicle → zone assignment ─────────────────────────────────────────────

    /** GET /api/vehicles/{id}/zones — zones a vehicle is assigned to. */
    public function vehicleZones(Request $request, int $id): JsonResponse
    {
        $vehicle = $this->accessibleVehicle($request, $id);

        $zones = $vehicle->zones()
            ->orderBy('name')
            ->get(['zones.id', 'zones.name', 'zones.slug'])
            ->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->name, 'slug' => $z->slug]);

        return response()->json([
            // Empty = unrestricted: the vehicle may enter / is visible in all zones.
            'unrestricted' => $zones->isEmpty(),
            'zones'        => $zones,
        ]);
    }

    /** PUT /api/vehicles/{id}/zones — set the vehicle's assigned zones. Admin only. */
    public function setVehicleZones(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);

        $vehicle = Vehicle::findOrFail($id);

        $data = $request->validate([
            'zone_ids'   => ['present', 'array'],
            'zone_ids.*' => ['integer', 'exists:zones,id'],
        ]);

        $old = $vehicle->zones()->orderBy('name')->pluck('zones.name')->all();
        $vehicle->zones()->sync($data['zone_ids']);
        $new = $vehicle->zones()->orderBy('name')->pluck('zones.name')->all();
        $this->logZoneChange($vehicle, $old, $new, 'manual', $request);

        return $this->vehicleZones($request, $id);
    }

    /** GET /api/vehicles/{id}/zone-log — recent zone-assignment changes for a vehicle. */
    public function vehicleZoneLog(Request $request, int $id): JsonResponse
    {
        $vehicle = $this->accessibleVehicle($request, $id);

        $logs = VehicleZoneChangelog::with('user:id,name')
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('changed_at')
            ->limit(50)
            ->get()
            ->map(fn (VehicleZoneChangelog $l) => [
                'id'         => $l->id,
                'old_zones'  => $l->old_zones ?? [],
                'new_zones'  => $l->new_zones ?? [],
                'source'     => $l->source,
                'changed_by' => $l->user?->name ?? 'System',
                'changed_at' => $l->changed_at,
            ]);

        return response()->json($logs);
    }

    /** Record a vehicle→zone assignment change (skips no-op changes). */
    private function logZoneChange(Vehicle $vehicle, array $old, array $new, string $source, Request $request): void
    {
        sort($old);
        sort($new);
        if ($old === $new) {
            return;
        }

        VehicleZoneChangelog::create([
            'vehicle_id' => $vehicle->id,
            'plate_no'   => $vehicle->plate_no,
            'old_zones'  => $old,
            'new_zones'  => $new,
            'source'     => $source,
            'user_id'    => $request->user()?->id,
            'ip_address' => $request->ip(),
            'changed_at' => now(),
        ]);
    }

    // ── Bulk import (Excel → assignments) ─────────────────────────────────────

    /**
     * POST /api/zones/import — bulk-assign vehicles to *existing* zones from a
     * spreadsheet.
     *
     * The client parses the workbook and posts resolved rows: each `{ plate,
     * zone }`. Vehicles are matched by a normalised plate (case/space/punctuation
     * insensitive) and zones by name (slug) against the EXISTING canonical zones —
     * the import never creates zones. A row whose zone name has no matching zone
     * is reported under `unmatched_zones` (same idea as `unmatched_plates`); add
     * the zone first, then re-import. With `apply=false` (default) nothing is
     * written. With `apply=true` each vehicle that matched at least one existing
     * zone has its zone set replaced with those zones.
     */
    public function import(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'rows'          => ['present', 'array'],
            'rows.*.plate'  => ['required', 'string'],
            'rows.*.zone'   => ['required', 'string'],
            'rows.*.site'   => ['nullable', 'string'],
            'apply'         => ['sometimes', 'boolean'],
        ]);

        $apply = (bool) ($data['apply'] ?? false);

        // Normalised plate → vehicle index (strip spaces/punctuation, uppercase).
        $byPlate = [];
        foreach (Vehicle::get(['id', 'plate_no']) as $v) {
            $byPlate[$this->normalizePlate($v->plate_no)] = $v;
        }

        // Existing canonical zones keyed by slug — the only zones we assign to.
        $zoneBySlug = Zone::get(['id', 'name', 'slug'])->keyBy('slug');

        $matched = [];            // vehicleId => ['plate_no'=>, 'zones'=>[slug=>name]]
        $unmatchedPlates = [];    // plate strings with no vehicle
        $unmatchedZones = [];     // zone names with no existing canonical zone

        foreach ($data['rows'] as $row) {
            $rawPlate = trim($row['plate']);
            $zoneName = $this->cleanName($row['zone']);
            if ($zoneName === '') {
                continue;
            }
            $zoneSlug = Str::slug($zoneName);
            $zone     = $zoneBySlug->get($zoneSlug);

            // A zone that doesn't exist is reported, not created.
            if (! $zone) {
                $unmatchedZones[$zoneName] = true;
            }

            $key     = $this->normalizePlate($rawPlate);
            $vehicle = $key !== '' ? ($byPlate[$key] ?? null) : null;

            if (! $vehicle) {
                $unmatchedPlates[$rawPlate] = true;
                continue;
            }

            // Only pair a matched vehicle with a matched (existing) zone.
            if ($zone) {
                $matched[$vehicle->id] ??= ['plate_no' => $vehicle->plate_no, 'zones' => []];
                $matched[$vehicle->id]['zones'][$zoneSlug] = $zoneName;
            }
        }

        $assignedVehicles = 0;

        if ($apply) {
            foreach ($matched as $vehicleId => $info) {
                $vehicle = Vehicle::find($vehicleId);
                if (! $vehicle) {
                    continue;
                }
                $zoneIds = [];
                foreach (array_keys($info['zones']) as $slug) {
                    $zoneIds[] = $zoneBySlug->get($slug)->id;
                }
                $old = $vehicle->zones()->orderBy('name')->pluck('zones.name')->all();
                $vehicle->zones()->sync($zoneIds);
                $new = $vehicle->zones()->orderBy('name')->pluck('zones.name')->all();
                $this->logZoneChange($vehicle, $old, $new, 'import', $request);
                $assignedVehicles++;
            }
        }

        // Distinct existing zones actually referenced (for the report).
        $zonesMatched = collect($matched)
            ->flatMap(fn ($m) => array_values($m['zones']))
            ->unique()->values();

        return response()->json([
            'applied'           => $apply,
            'matched_count'     => count($matched),
            'unmatched_count'   => count($unmatchedPlates),
            'unmatched_plates'  => array_values(array_keys($unmatchedPlates)),
            'unmatched_zones'   => array_values(array_keys($unmatchedZones)),
            'zones_matched'     => $zonesMatched,
            'assigned_vehicles' => $assignedVehicles,
            'matched'           => collect($matched)->map(fn ($m) => [
                'plate_no' => $m['plate_no'],
                'zones'    => array_values($m['zones']),
            ])->values(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Collapse whitespace and trim — canonical display name. */
    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** Plate match key: uppercase, strip everything but letters/digits. */
    private function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate));
    }

    private function formatZone(Zone $z): array
    {
        return [
            'id'             => $z->id,
            'name'           => $z->name,
            'slug'           => $z->slug,
            'status'         => $z->status,
            'is_open'        => (bool) $z->is_open,
            'source'         => $z->source,
            'created_at'     => $z->created_at,
            'created_by'     => $z->relationLoaded('creator') && $z->creator ? $z->creator->name : null,
            'site'           => $z->site ? ['id' => $z->site->id, 'name' => $z->site->name] : null,
            'geofence_count' => $z->geofences_count,
            'vehicle_count'  => $z->vehicles_count,
        ];
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->is_admin) {
            throw new AccessDeniedHttpException('Admin access required.');
        }
    }

    /** Load a vehicle the current user is allowed to see (admins see all). */
    private function accessibleVehicle(Request $request, int $id): Vehicle
    {
        $vehicle = $request->user()->vehicleQuery()->whereKey($id)->first();

        if (! $vehicle) {
            throw new AccessDeniedHttpException('Vehicle not accessible.');
        }

        return $vehicle;
    }
}
