<?php

namespace App\Console\Commands;

use App\Services\ZoneSiteSyncService;
use Illuminate\Console\Command;

/**
 * Rebuild the canonical Site / Zone layer from the raw provider mirror tables.
 * Runs automatically inside the full poll; also usable as a one-off backfill.
 */
class ZonesSyncCommand extends Command
{
    protected $signature   = 'zones:sync';
    protected $description = 'Rebuild canonical sites & zones (name-merged) from provider geofences/sites';

    public function handle(ZoneSiteSyncService $service): int
    {
        $result = $service->run();

        $this->info("Canonicalized {$result['sites']} provider sites and {$result['zones']} geofences.");

        return self::SUCCESS;
    }
}
