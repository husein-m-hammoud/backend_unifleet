<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Discrete events from DSCO (over_speed, geofence_exit, idle_start, etc.)
        // Append-only — never updated.
        Schema::create('vehicle_events', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_code');
            $table->string('event_name')->nullable();
            $table->double('speed')->default(0);
            $table->double('lat')->nullable();
            $table->double('lon')->nullable();
            $table->double('alt')->nullable();
            $table->string('reason')->nullable();
            $table->jsonb('arguments')->nullable(); // raw arguments array from DSCO

            $table->index(['vehicle_id', 'time']);
            $table->index(['event_code', 'time']);
            $table->index('time');
        });

        // On production (TimescaleDB):
        // SELECT create_hypertable('vehicle_events', 'time', if_not_exists => TRUE);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_events');
    }
};
