<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'actor_user_id', 'type', 'title', 'metadata'])]
class ActivityLog extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ActivityRecipient::class);
    }
}
