<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scoped `user` can be granted "access to all zones" — they see every vehicle
 * (like a manager for visibility) without being handed individual zone/site
 * grants. Managers are unaffected (they always see everything).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_zones')->default(false)->after('can_edit_vehicles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_zones');
        });
    }
};
