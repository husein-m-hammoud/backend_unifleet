<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only time-series table. One row per poll per vehicle.
        // Never updated — always INSERT.
        Schema::create('vehicle_positions', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->double('lat');
            $table->double('lon');
            $table->double('alt')->default(0);
            $table->double('speed')->default(0);
            $table->unsignedSmallInteger('heading')->default(0);
            $table->string('ignition_status')->nullable();  // IgnitionOn | IgnitionOff
            $table->double('odometer')->nullable();         // from vehicle/last-state
            $table->unsignedBigInteger('dsco_event_id')->nullable();
            $table->string('event_name')->nullable();
            $table->string('event_code')->nullable();

            $table->index(['vehicle_id', 'time']);
            $table->index('time');
        });

        // On production (Alibaba Cloud RDS with TimescaleDB), run after migration:
        // SELECT create_hypertable('vehicle_positions', 'time', if_not_exists => TRUE);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_positions');
    }
};
