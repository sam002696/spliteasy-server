<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['activity_log_id', 'user_id', 'read_at'])]
class ActivityRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
