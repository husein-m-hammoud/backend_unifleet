<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical, our-side zone — one row per distinct geofence *name*, merging the
 * per-provider geofence polygons that share a name. Optionally grouped under a
 * canonical site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();   // normalized name — the merge key
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
