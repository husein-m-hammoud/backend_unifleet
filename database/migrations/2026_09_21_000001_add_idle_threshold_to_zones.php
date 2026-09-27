<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-zone excessive-idle threshold (minutes). NULL = the zone has no specific
     * threshold, so a vehicle idling inside it falls back to its vehicle-type
     * override or the global `idle_threshold` setting. A vehicle inside a zone WITH
     * a threshold is held to that threshold regardless of its type — mirrors the
     * per-site `speed_limit` model, but keyed on the finer canonical zone.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->unsignedInteger('idle_threshold')->nullable()->after('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('idle_threshold');
        });
    }
};
