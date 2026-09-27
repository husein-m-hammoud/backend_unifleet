<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The `sites` table is the raw per-provider mirror synced from Safee (one row
 * per provider's site id). We rename it to `provider_sites` so the natural name
 * `sites` can hold the canonical, name-merged site (see later migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('sites', 'provider_sites');
    }

    public function down(): void
    {
        Schema::rename('provider_sites', 'sites');
    }
};
