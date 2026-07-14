<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which Safee provider a user logged in through. A user's accessible
 * vehicle IDs are only unique within a provider, so all vehicle/site scoping
 * must be filtered by (provider, dsco_*_id) — see User::vehicleQuery().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider', 50)->nullable()->after('dsco_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
