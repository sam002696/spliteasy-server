<?php

namespace App\Services\Notification;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\User;

class NotificationVisibilityService
{
    public function applyVisibleActivityFilter($query, User $user): void
    {
        $query
            ->whereNotIn('type', $this->hiddenActivityTypes())
            ->where(function ($query) use ($user): void {
                $query
                    ->whereNotIn('type', $this->hiddenSelfActivityTypes())
                    ->orWhere('actor_user_id', '!=', $user->id)
                    ->orWhereNull('actor_user_id');
            });
    }

    public function shouldShowToUser(ActivityLog $activity, User $user): bool
    {
        if (in_array($activity->type, $this->hiddenActivityTypes(), true)) {
            return false;
        }

        if (
            in_array($activity->type, $this->hiddenSelfActivityTypes(), true)
            && $activity->actor_user_id === $user->id
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function hiddenActivityTypes(): array
    {
        return [
            ActivityType::GroupCreated->value,
            ActivityType::GroupDeleted->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function hiddenSelfActivityTypes(): array
    {
        return [
            ActivityType::GroupInvitationSent->value,
            ActivityType::ExpenseCreated->value,
            ActivityType::SettlementCreated->value,
        ];
    }
}
