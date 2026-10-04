<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event email recipients.
 *
 * Previously each email had its recipient hard-wired: account emails went to the
 * user and nobody else, the report digest read one `report_email_recipients`
 * setting, and ops alerts read one env var. There was no way to copy a manager
 * on anything.
 *
 * One row per event. `to`/`cc` hold comma-separated address lists and are
 * ADDITIVE — for an event that is about a specific person (account created,
 * password reset) that person is still the primary recipient; these only add to
 * it. For fleet-wide events (reports, ops alerts) `to` is the whole recipient
 * list, so an empty `to` means the email does not go out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->string('event')->unique();     // e.g. account_created
            $table->boolean('enabled')->default(true);
            $table->text('to')->nullable();        // comma-separated
            $table->text('cc')->nullable();        // comma-separated
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
