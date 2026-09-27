<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-vehicle-type idle threshold overrides (minutes). A type without a row
     * here falls back to the global `idle_threshold` setting.
     */
    public function up(): void
    {
        Schema::create('vehicle_type_idle_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->unsignedInteger('idle_threshold'); // minutes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_type_idle_thresholds');
    }
};
