<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'group_id',
    'created_by_user_id',
    'paid_by_user_id',
    'paid_to_user_id',
    'amount',
    'currency',
    'settled_at',
])]
class Settlement extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
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

    public function paidTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_to_user_id');
    }
}
