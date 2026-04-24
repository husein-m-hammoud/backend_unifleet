<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dsco_vehicle_id')->unique();
            $table->uuid('dsco_uuid')->nullable();
            $table->string('plate_no');
            $table->string('type')->nullable(); // TRUCK, GENERATOR, JCB, EXCAVATOR, etc.

            // Company (denormalized — DSCO embeds it in vehicle response)
            $table->unsignedBigInteger('dsco_company_id')->nullable();
            $table->string('dsco_company_name')->nullable();

            // Site & Category — reference DSCO IDs (join to local sites/categories tables)
            $table->unsignedBigInteger('dsco_site_id')->nullable();
            $table->unsignedBigInteger('dsco_category_id')->nullable();

            // Currently assigned driver (FK to local drivers)
            // null = no driver assigned right now
            $table->foreignId('current_driver_id')
                  ->nullable()
                  ->constrained('drivers')
                  ->nullOnDelete();

            // Vehicle details
            $table->string('vin')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->timestamp('dsco_created_at')->nullable();
            $table->timestamp('dsco_first_trip_date')->nullable();
            $table->timestamp('dsco_last_trip_date')->nullable();

            // Tracking device embedded in vehicle
            $table->unsignedBigInteger('dsco_device_id')->nullable();
            $table->string('device_sim')->nullable();
            $table->string('device_imei')->nullable();
            $table->string('device_type')->nullable();   // TELTONIKA, etc.
            $table->string('device_serial')->nullable();
            $table->timestamp('device_installation_date')->nullable();

            // Soft-disable logic
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('dsco_last_seen_at')->nullable();

            $table->timestamps();

            $table->index('dsco_site_id');
            $table->index('dsco_category_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
