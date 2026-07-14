<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_changelogs', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key');
            $table->string('setting_label');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index('setting_key');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changelogs');
    }
};
