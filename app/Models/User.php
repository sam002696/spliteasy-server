<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function groupInvitations(): HasMany
    {
        return $this->hasMany(GroupInvitation::class, 'invited_user_id');
    }

    public function paidExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'paid_by_user_id');
    }

    public function expenseSplits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class);
    }

    public function settlementsPaid(): HasMany
    {
        return $this->hasMany(Settlement::class, 'paid_by_user_id');
    }

    public function settlementsReceived(): HasMany
    {
        return $this->hasMany(Settlement::class, 'paid_to_user_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'actor_user_id');
    }

    public function activityRecipients(): HasMany
    {
        return $this->hasMany(ActivityRecipient::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
