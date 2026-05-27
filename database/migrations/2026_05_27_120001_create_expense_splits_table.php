<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('share_value', 12, 2)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['expense_id', 'user_id']);
            $table->index(['user_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_splits');
    }
};
