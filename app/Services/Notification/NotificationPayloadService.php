<?php

namespace App\Services\Notification;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\ActivityRecipient;
use App\Models\User;

class NotificationPayloadService
{
    public function build(ActivityRecipient $recipient, User $user): array
    {
        $activity = $recipient->activityLog;
        $metadata = $activity?->metadata ?? [];

        return [
            'id' => $recipient->id,
            'activity_id' => $activity?->id,
            'type' => $activity?->type,
            'title' => $activity ? $this->activityTitle($activity, $user, $metadata) : null,
            'subtitle' => $activity?->group?->name,
            'amount' => $metadata['amount'] ?? null,
            'currency' => $metadata['currency'] ?? null,
            'is_read' => ! is_null($recipient->read_at),
            'read_at' => $recipient->read_at,
            'actor' => $activity?->actor ? [
                'id' => $activity->actor->id,
                'name' => $activity->actor->name,
                'initials' => $this->initials($activity->actor->name),
            ] : null,
            'group' => $activity?->group ? [
                'id' => $activity->group->id,
                'name' => $activity->group->name,
            ] : null,
            'metadata' => $metadata,
            'created_at' => $activity?->created_at,
        ];
    }

    private function activityTitle(ActivityLog $activity, User $user, array $metadata): string
    {
        $actorIsCurrentUser = $activity->actor_user_id === $user->id;

        return match ($activity->type) {
            ActivityType::ExpenseCreated->value => $actorIsCurrentUser
                ? 'You added '.($metadata['description'] ?? 'an expense')
                : $activity->title,

            ActivityType::SettlementCreated->value => $actorIsCurrentUser
                ? 'You settled with '.($metadata['paid_to_name'] ?? 'a member')
                : $activity->title,

            ActivityType::GroupDeleted->value => $actorIsCurrentUser
                ? 'You deleted '.($metadata['group_name'] ?? 'a group')
                : $activity->title,

            ActivityType::GroupMemberLeft->value => $actorIsCurrentUser
                ? 'You left '.($metadata['group_name'] ?? 'a group')
                : $activity->title,

            ActivityType::GroupMemberRemoved->value => $actorIsCurrentUser
                ? 'You removed '.($metadata['removed_user_name'] ?? 'a member')
                : $activity->title,

            ActivityType::GroupInvitationSent->value => $actorIsCurrentUser
                ? 'You invited '.($metadata['invited_user_name'] ?? 'a member')
                : $activity->title,

            ActivityType::GroupInvitationAccepted->value => $actorIsCurrentUser
                ? 'You joined '.($metadata['group_name'] ?? 'a group')
                : $activity->title,

            ActivityType::GroupInvitationRejected->value => $actorIsCurrentUser
                ? 'You rejected '.($metadata['group_name'] ?? 'a group').' invitation'
                : $activity->title,

            default => $activity->title,
        };
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
            ->implode('');
    }
}
