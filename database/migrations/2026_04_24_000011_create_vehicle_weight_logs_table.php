<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weight/load snapshots per vehicle from vehicle/get-weight-data. Append-only.
        Schema::create('vehicle_weight_logs', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->double('weight')->nullable();
            $table->jsonb('raw')->nullable(); // full API response (schema unknown until DSCO confirms)

            $table->index(['vehicle_id', 'time']);
            $table->index('time');
        });

        // On production (TimescaleDB):
        // SELECT create_hypertable('vehicle_weight_logs', 'time', if_not_exists => TRUE);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_weight_logs');
    }
};
