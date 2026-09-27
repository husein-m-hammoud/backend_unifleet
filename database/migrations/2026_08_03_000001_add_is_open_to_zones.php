<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An "open" zone is one every vehicle may work in — it is excluded from the
     * unauthorized-zone check, so a vehicle entering it never raises an alert.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('is_open');
        });
    }
};
