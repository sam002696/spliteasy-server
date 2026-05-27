<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['expense_id', 'user_id', 'amount', 'share_value', 'settled_at'])]
class ExpenseSplit extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'share_value' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
