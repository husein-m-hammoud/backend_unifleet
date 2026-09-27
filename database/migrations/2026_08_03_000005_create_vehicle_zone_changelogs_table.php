<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for vehicle → zone assignment changes: who changed it, whether
     * it came from the admin UI (`manual`) or the Excel import (`import`), when,
     * and the before/after zone names.
     */
    public function up(): void
    {
        Schema::create('vehicle_zone_changelogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('plate_no')->nullable();     // snapshot for display
            $table->json('old_zones')->nullable();       // array of zone names
            $table->json('new_zones')->nullable();       // array of zone names
            $table->string('source');                    // manual | import
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['vehicle_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_zone_changelogs');
    }
};
