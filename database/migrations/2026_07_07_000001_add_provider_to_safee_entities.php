<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-provider support (Safee): DSCO is being retired in favour of the Safee
 * Tracking platform, with Alrakeen now and saudiX later. Different providers can
 * reuse the same numeric entity IDs, so the external-id uniqueness must be scoped
 * per provider.
 *
 * Existing rows were sourced from DSCO, so they are backfilled with provider='dsco'.
 * The poller stamps every synced row with its provider going forward.
 */
return new class extends Migration
{
    /** table => external-id column */
    private array $tables = [
        'sites'      => 'dsco_site_id',
        'categories' => 'dsco_category_id',
        'geofences'  => 'dsco_geofence_id',
        'drivers'    => 'dsco_driver_id',
        'vehicles'   => 'dsco_vehicle_id',
        'trips'      => 'dsco_trip_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $externalId) {
            Schema::table($table, function (Blueprint $t) use ($externalId) {
                $t->string('provider', 50)->default('dsco')->after('id');
                $t->index('provider');
            });

            // Swap single-column uniqueness for (provider, external_id).
            Schema::table($table, function (Blueprint $t) use ($externalId) {
                $t->dropUnique([$externalId]);
                $t->unique(['provider', $externalId]);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $externalId) {
            Schema::table($table, function (Blueprint $t) use ($externalId) {
                $t->dropUnique(['provider', $externalId]);
                $t->unique([$externalId]);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['provider']);
                $t->dropColumn('provider');
            });
        }
    }
};
