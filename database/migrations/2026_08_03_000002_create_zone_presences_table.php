<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks a vehicle's *ongoing* presence inside a zone it is NOT assigned to.
     * This is a provisional record, not an alert: a vehicle merely passing
     * through should not alert. Only once its dwell time exceeds the
     * `unauthorized_zone_threshold` setting (or it goes offline inside the zone)
     * is the row promoted to a real `zone_unauthorized` alert (`alerted_at` set,
     * `alert_id` linked). The row is deleted when the vehicle leaves the zone.
     */
    public function up(): void
    {
        Schema::create('zone_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('alerted_at')->nullable();
            $table->foreignId('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // A vehicle is physically in at most one zone at a time.
            $table->unique('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_presences');
    }
};
