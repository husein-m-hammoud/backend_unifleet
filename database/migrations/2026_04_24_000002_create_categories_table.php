<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dsco_category_id')->unique();
            $table->unsignedBigInteger('dsco_site_id');
            $table->unsignedBigInteger('dsco_parent_id')->nullable(); // null = root category
            $table->string('name');
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('dsco_last_seen_at')->nullable();
            $table->timestamps();

            $table->index('dsco_site_id');
            $table->index('dsco_parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
