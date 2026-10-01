<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable record of every Safee API failure, with a timestamp.
 *
 * The Sep 2026 outage was invisible because failures only ever went to
 * laravel.log — by the time anyone looked, the answer to "when did Alrakeen
 * start failing?" required grepping 7 MB of mixed log output. This table makes
 * that a query.
 *
 * Only FAILURES are stored (successes would be ~2 rows/second at the live-poll
 * cadence); the last success timestamp is kept in the cache instead. Pruned by
 * `unifleet:doctor --prune` / the scheduled prune so it stays bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_api_failures', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();          // e.g. alrakeen
            $table->string('kind');                        // auth | request
            $table->string('endpoint')->nullable();        // api/v2/vehicle/last-state
            $table->integer('status_code')->nullable();    // HTTP status, null on connect failure
            $table->text('message')->nullable();           // trimmed exception message
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // "What failed, for which provider, when" — the query the diagnostics
            // page and the doctor command both run.
            $table->index(['provider', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_api_failures');
    }
};
