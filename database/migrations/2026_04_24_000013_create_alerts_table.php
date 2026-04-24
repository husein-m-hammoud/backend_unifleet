<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // geofence_exit, idle, over_speed, anomaly, etc.
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('twilio_status')->nullable(); // pending, sent, failed
            $table->jsonb('meta')->nullable();           // extra context (zone name, speed, etc.)
            $table->timestamps();

            $table->index(['vehicle_id', 'triggered_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
