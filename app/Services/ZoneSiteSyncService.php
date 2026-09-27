<?php

namespace App\Services;

use App\Models\Geofence;
use App\Models\ProviderSite;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * Builds the canonical, name-based Site / Zone layer from the raw per-provider
 * mirror tables. The same physical site/zone exists in BOTH providers with
 * different ids but the same name, so we merge on a normalized name (slug):
 *
 *   provider_sites (per provider) ──by name──► sites  (canonical)
 *   geofences      (per provider) ──by name──► zones  (canonical)
 *
 * Idempotent: safe to run on every full poll and as a one-off backfill.
 */
class ZoneSiteSyncService
{
    /**
     * @return array{sites:int, zones:int} number of canonical rows touched
     */
    public function run(): array
    {
        return [
            'sites' => $this->canonicalizeSites(),
            'zones' => $this->canonicalizeZones(),
        ];
    }

    /** Merge provider_sites → canonical sites by name; backlink provider rows. */
    public function canonicalizeSites(): int
    {
        $touched = 0;

        ProviderSite::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$touched) {
                foreach ($rows as $row) {
                    $slug = $this->slug($row->name);
                    if ($slug === '') {
                        continue;
                    }

                    $site = Site::firstOrCreate(
                        ['slug' => $slug],
                        ['name' => $this->cleanName($row->name), 'status' => 'active', 'source' => 'provider'],
                    );

                    if ($row->site_id !== $site->id) {
                        $row->update(['site_id' => $site->id]);
                    }
                    $touched++;
                }
            });

        return $touched;
    }

    /** Merge geofences → canonical zones by name; backlink geofence rows. */
    public function canonicalizeZones(): int
    {
        $touched = 0;

        Geofence::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$touched) {
                foreach ($rows as $row) {
                    $slug = $this->slug($row->name);
                    if ($slug === '') {
                        continue;
                    }

                    $zone = Zone::firstOrCreate(
                        ['slug' => $slug],
                        ['name' => $this->cleanName($row->name), 'status' => 'active', 'source' => 'provider'],
                    );

                    if ($row->zone_id !== $zone->id) {
                        $row->update(['zone_id' => $zone->id]);
                    }
                    $touched++;
                }
            });

        return $touched;
    }

    /** Display name: trimmed, internal whitespace collapsed. */
    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** Merge key: lowercase slug of the cleaned name (e.g. "Zone-01 " → "zone-01"). */
    private function slug(string $name): string
    {
        return Str::slug($this->cleanName($name));
    }
}
