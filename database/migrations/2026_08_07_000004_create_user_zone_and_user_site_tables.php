<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access grants for scoped `user` accounts. A user sees a vehicle when the
     * vehicle is assigned to one of their granted zones — either a zone granted
     * directly (`user_zone`) or any zone under a granted site (`user_site`, which
     * also covers zones added to that site later). Managers (owner/admin) ignore
     * these tables and see the whole fleet.
     */
    public function up(): void
    {
        Schema::create('user_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'zone_id']);
        });

        Schema::create('user_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_zone');
        Schema::dropIfExists('user_site');
    }
};
