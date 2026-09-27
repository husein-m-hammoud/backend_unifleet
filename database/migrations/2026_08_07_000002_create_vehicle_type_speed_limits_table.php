<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-vehicle-type over-speed limit overrides (km/h). A type without a row
     * here falls back to the global `speed_limit` setting. Mirrors
     * `vehicle_type_idle_thresholds`. Note: a site-specific limit still takes
     * precedence over the type limit while the vehicle is inside that site.
     */
    public function up(): void
    {
        Schema::create('vehicle_type_speed_limits', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->unsignedInteger('speed_limit'); // km/h
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_type_speed_limits');
    }
};
