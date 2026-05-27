<?php

namespace App\Models;

use App\Enums\ExpenseSplitMethod;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'group_id',
    'created_by_user_id',
    'paid_by_user_id',
    'description',
    'amount',
    'currency',
    'expense_date',
    'split_method',
    'status',
])]
class Expense extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'split_method' => ExpenseSplitMethod::class,
            'status' => ExpenseStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class);
    }
}
