<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each raw provider geofence to its canonical zone (populated by name
 * during canonicalization). Both providers' copies point at the same zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('id')->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });
    }
};
