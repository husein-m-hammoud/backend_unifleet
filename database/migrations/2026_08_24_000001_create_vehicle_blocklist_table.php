<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicles the user has explicitly deleted. The poller consults this so a
     * deleted vehicle is never re-created from the provider API on the next sync.
     * Keyed on (provider, dsco_vehicle_id) — the same identity the poller upserts on.
     */
    public function up(): void
    {
        Schema::create('vehicle_blocklist', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->unsignedBigInteger('dsco_vehicle_id');
            $table->string('plate_no')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'dsco_vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_blocklist');
    }
};
