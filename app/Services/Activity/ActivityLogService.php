<?php

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Events\NotificationCreated;
use App\Jobs\SendExpoPushNotificationJob;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\User;
use App\Services\Notification\NotificationPayloadService;
use App\Services\Notification\NotificationVisibilityService;
use Illuminate\Support\Collection;

class ActivityLogService
{
    public function __construct(
        private readonly NotificationVisibilityService $notificationVisibilityService,
        private readonly NotificationPayloadService $notificationPayloadService
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  iterable<int, int|User>  $recipients
     */
    public function record(
        ActivityType $type,
        string $title,
        ?Group $group,
        ?User $actor,
        array $metadata,
        iterable $recipients
    ): ActivityLog {
        $activity = ActivityLog::query()->create([
            'group_id' => $group?->id,
            'actor_user_id' => $actor?->id,
            'type' => $type->value,
            'title' => $title,
            'metadata' => $metadata,
        ])->load(['group', 'actor']);

        $recipientIds = $this->normalizeRecipientIds($recipients);
        $users = User::query()
            ->whereIn('id', $recipientIds)
            ->get()
            ->keyBy('id');

        foreach ($recipientIds as $userId) {
            $recipient = $activity->recipients()->create([
                'user_id' => $userId,
            ]);

            $user = $users->get($userId);

            if (! $user || ! $this->notificationVisibilityService->shouldShowToUser($activity, $user)) {
                continue;
            }

            $recipient->setRelation('activityLog', $activity);
            $payload = $this->notificationPayloadService->build($recipient, $user);

            NotificationCreated::dispatch(
                $user->id,
                $payload
            );

            SendExpoPushNotificationJob::dispatch($user->id, $payload)->afterCommit();
        }

        return $activity;
    }

    public function groupMemberIds(Group $group): Collection
    {
        return $group->members()
            ->pluck('users.id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values();
    }

    /**
     * @param  iterable<int, int|User>  $recipients
     * @return list<int>
     */
    private function normalizeRecipientIds(iterable $recipients): array
    {
        return collect($recipients)
            ->map(fn (int|User $recipient): int => $recipient instanceof User ? $recipient->id : $recipient)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
