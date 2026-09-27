<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle → zone assignment ("can enter these zones"). A vehicle with NO rows
 * here is unrestricted — it may enter / is visible in all zones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vehicle_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_zone');
    }
};
