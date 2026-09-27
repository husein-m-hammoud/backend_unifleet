<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which zones a contact is explicitly responsible for. Ignored for contacts
     * flagged `all_zones` (they cover every zone without needing rows here).
     */
    public function up(): void
    {
        Schema::create('contact_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_zone');
    }
};
