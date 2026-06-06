<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_push_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('expo');
            $table->string('token')->unique();
            $table->string('platform', 20)->nullable();
            $table->string('device_id')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'revoked_at']);
            $table->index(['device_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
    }
};
