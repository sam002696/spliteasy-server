<?php

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class ActivityLogService
{
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
        ]);

        foreach ($this->normalizeRecipientIds($recipients) as $userId) {
            $activity->recipients()->create([
                'user_id' => $userId,
            ]);
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
