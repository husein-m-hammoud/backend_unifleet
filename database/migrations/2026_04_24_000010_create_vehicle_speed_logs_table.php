<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Speed snapshots per vehicle from vehicle/get-speed-data. Append-only.
        Schema::create('vehicle_speed_logs', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->double('speed')->nullable();
            $table->double('max_speed')->nullable();
            $table->double('avg_speed')->nullable();
            $table->jsonb('raw')->nullable(); // full API response for future fields

            $table->index(['vehicle_id', 'time']);
            $table->index('time');
        });

        // On production (TimescaleDB):
        // SELECT create_hypertable('vehicle_speed_logs', 'time', if_not_exists => TRUE);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_speed_logs');
    }
};
