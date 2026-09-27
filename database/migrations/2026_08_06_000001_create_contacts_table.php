<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zone responsibles — people to notify about an issue in a zone. A contact is
     * assigned to specific zones (contact_zone pivot) OR flagged `all_zones`, in
     * which case they are responsible for every zone, including ones added later.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // required
            $table->string('phone');                // required
            $table->string('email')->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            // Responsible for every zone (current + future) — overrides the pivot.
            $table->boolean('all_zones')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
