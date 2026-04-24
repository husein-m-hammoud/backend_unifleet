<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dsco_driver_id')->unique();
            $table->uuid('dsco_uuid')->nullable();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('gender')->nullable();
            $table->string('email')->nullable();
            $table->string('license_status')->nullable();   // e.g. Unknown, Valid, Expired
            $table->string('residency_status')->nullable(); // e.g. Unknown, Valid, Expired
            $table->string('access_key')->nullable();       // RFID / badge key
            $table->string('badge_number_1')->nullable();
            $table->string('badge_number_2')->nullable();
            $table->string('status')->default('active');    // active | disabled
            $table->timestamp('dsco_last_seen_at')->nullable();
            $table->timestamp('dsco_created_at')->nullable();
            $table->timestamp('dsco_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
