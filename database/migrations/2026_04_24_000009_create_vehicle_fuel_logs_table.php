<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fuel snapshots per vehicle. Append-only.
        Schema::create('vehicle_fuel_logs', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->double('fuel_liters')->nullable();
            $table->double('fuel_pct')->nullable();
            $table->double('total_fuel_used')->nullable();
            $table->double('total_idle_fuel_used')->nullable();
            $table->double('fuel_consumption_per_100km')->nullable();
            $table->double('range_km')->nullable();
            $table->boolean('fuel_low_indicator')->nullable();

            $table->index(['vehicle_id', 'time']);
            $table->index('time');
        });

        // On production (TimescaleDB):
        // SELECT create_hypertable('vehicle_fuel_logs', 'time', if_not_exists => TRUE);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_fuel_logs');
    }
};
