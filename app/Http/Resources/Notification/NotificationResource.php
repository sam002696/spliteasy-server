<?php

namespace App\Http\Resources\Notification;

use App\Enums\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activity = $this->activityLog;
        $metadata = $activity?->metadata ?? [];

        return [
            'id' => $this->id,
            'activity_id' => $activity?->id,
            'type' => $activity?->type,
            'title' => $activity ? $this->activityTitle($activity, $request->user()?->id, $metadata) : null,
            'subtitle' => $activity?->group?->name,
            'amount' => $metadata['amount'] ?? null,
            'currency' => $metadata['currency'] ?? null,
            'is_read' => ! is_null($this->read_at),
            'read_at' => $this->read_at,
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

    private function activityTitle($activity, ?int $currentUserId, array $metadata): string
    {
        $actorIsCurrentUser = $activity->actor_user_id === $currentUserId;

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
