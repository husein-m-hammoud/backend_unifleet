<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance for the canonical Zone/Site layer: whether a row was created
     * from the provider API (`provider`, via canonicalization) or added by hand
     * in our UI (`manual`), plus which user added a manual one. Existing rows
     * default to `provider` (they came from the sync).
     */
    public function up(): void
    {
        foreach (['zones', 'sites'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('source')->default('provider')->after('status'); // provider | manual
                $t->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['zones', 'sites'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_by');
                $t->dropColumn('source');
            });
        }
    }
};
