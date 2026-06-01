<?php

namespace App\Services\Notification;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\ActivityRecipient;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class NotificationService
{
    /**
     * @throws ValidationException
     */
    public function getUserNotifications(User $user, string $filter = 'all', int $perPage = 20): LengthAwarePaginator
    {
        $filter = strtolower($filter);

        if (! in_array($filter, ['all', 'unread', 'read'], true)) {
            throw ValidationException::withMessages([
                'filter' => ['Invalid notification filter. Allowed values are all, unread, read.'],
            ]);
        }

        $perPage = max(1, min($perPage, 50));

        return ActivityRecipient::query()
            ->with(['activityLog.group', 'activityLog.actor'])
            ->where('user_id', $user->id)
            ->whereHas('activityLog', function ($query) use ($user): void {
                $this->applyVisibleActivityFilter($query, $user);
            })
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($filter === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->orderByDesc(
                ActivityLog::query()
                    ->select('created_at')
                    ->whereColumn('activity_logs.id', 'activity_recipients.activity_log_id')
                    ->limit(1)
            )
            ->paginate($perPage);
    }

    public function getUnreadCount(User $user): int
    {
        return ActivityRecipient::query()
            ->where('user_id', $user->id)
            ->whereHas('activityLog', fn ($query) => $this->applyVisibleActivityFilter($query, $user))
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @throws AuthorizationException
     */
    public function markAsRead(ActivityRecipient $notification, User $user): ActivityRecipient
    {
        $this->ensureNotificationBelongsToUser($notification, $user);

        if (! $notification->read_at) {
            $notification->update([
                'read_at' => now(),
            ]);
        }

        return $notification->load(['activityLog.group', 'activityLog.actor']);
    }

    public function markAllAsRead(User $user): int
    {
        return ActivityRecipient::query()
            ->where('user_id', $user->id)
            ->whereHas('activityLog', fn ($query) => $this->applyVisibleActivityFilter($query, $user))
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureNotificationBelongsToUser(ActivityRecipient $notification, User $user): void
    {
        if ($notification->user_id !== $user->id) {
            throw new AuthorizationException('This notification does not belong to you.');
        }
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

    private function applyVisibleActivityFilter($query, User $user): void
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
