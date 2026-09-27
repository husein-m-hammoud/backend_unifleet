<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-site over-speed limit (km/h). NULL = the site has no specific limit, so
     * vehicles inside it fall back to their vehicle-type limit or the global
     * `speed_limit` setting. A vehicle inside a site WITH a limit is held to that
     * limit regardless of its type (e.g. Qiddiya = 50 km/h for everyone).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('speed_limit')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('speed_limit');
        });
    }
};
