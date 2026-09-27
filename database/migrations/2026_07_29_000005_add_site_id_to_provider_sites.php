<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each raw provider site to its canonical site (populated by name during
 * canonicalization).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_sites', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('id')->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
