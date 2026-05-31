<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('paid_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('paid_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index(['group_id', 'paid_by_user_id', 'paid_to_user_id']);
            $table->index(['created_by_user_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
