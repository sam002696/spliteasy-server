<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_log_id', 'user_id']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_recipients');
    }
};
