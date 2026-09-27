<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Who generated it — so the saved-reports list respects user scoping.
            $table->foreignId('created_by')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
            // Human title + the period the snapshot covers.
            $table->string('title')->nullable()->after('type');
            $table->timestamp('period_from')->nullable()->after('generated_at');
            $table->timestamp('period_to')->nullable()->after('period_from');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['title', 'period_from', 'period_to']);
        });
    }
};
