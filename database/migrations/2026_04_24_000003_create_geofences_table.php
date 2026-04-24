<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dsco_geofence_id')->unique();
            $table->uuid('dsco_uuid')->nullable();
            $table->string('name');
            $table->string('code')->nullable();           // e.g. "Geofence"
            $table->unsignedBigInteger('dsco_company_id')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('points_json');                 // array of {lat, lon, alt}
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('dsco_last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
