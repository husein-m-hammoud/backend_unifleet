<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // History of every driver <-> vehicle assignment.
        // A new row is inserted whenever the poller detects a driver change on a vehicle.
        // ended_at = null means the assignment is still active.
        Schema::create('vehicle_driver_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'started_at']);
            $table->index(['driver_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_driver_assignments');
    }
};
