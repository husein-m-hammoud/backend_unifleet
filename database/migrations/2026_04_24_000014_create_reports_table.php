<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // daily | weekly | monthly
            $table->timestamp('generated_at');
            $table->longText('content');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
