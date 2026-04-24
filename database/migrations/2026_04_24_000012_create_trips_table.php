<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dsco_trip_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->double('distance')->default(0);       // meters
            $table->double('avg_speed')->default(0);
            $table->double('max_speed')->default(0);
            $table->unsignedInteger('idle_time')->default(0);    // seconds
            $table->unsignedInteger('driving_time')->default(0); // seconds
            $table->boolean('completed')->default(false);

            // Start / end GPS location
            $table->double('start_lat')->nullable();
            $table->double('start_lon')->nullable();
            $table->double('start_alt')->nullable();
            $table->double('end_lat')->nullable();
            $table->double('end_lon')->nullable();
            $table->double('end_alt')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'start_time']);
            $table->index(['driver_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
