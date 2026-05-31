<?php

namespace App\Http\Resources\Notification;

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
            'title' => $activity?->title,
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

    private function initials(string $name): string
    {
        return collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
            ->implode('');
    }
}
