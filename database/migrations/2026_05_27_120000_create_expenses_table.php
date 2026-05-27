<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('paid_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('expense_date');
            $table->string('split_method', 20)->default('equal');
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->index(['group_id', 'expense_date']);
            $table->index(['paid_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
