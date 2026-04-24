<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // DSCO login identity — unique per DSCO account
            $table->string('dsco_username')->unique()->nullable()->after('email');

            // Vehicle IDs this user can access (populated at login from vehicle/list-info)
            // e.g. [116990361252, 117001688100]
            $table->jsonb('dsco_accessible_vehicle_ids')->nullable()->after('dsco_username');

            // DSCO users have no local password
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dsco_username', 'dsco_accessible_vehicle_ids']);
        });
    }
};
