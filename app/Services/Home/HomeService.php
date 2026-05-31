<?php

namespace App\Services\Home;

use App\Enums\ActivityType;
use App\Enums\ExpenseStatus;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class HomeService
{
    public function getHomeData(User $user): array
    {
        $groups = $user->groups()
            ->with(['latestExpense.paidBy', 'memberships.user', 'expenses.splits'])
            ->withCount(['members', 'expenses'])
            ->get();

        $activeGroups = $this->buildActiveGroups($groups);

        return [
            'summary' => $this->buildSummary($groups, $user),
            'active_groups_count' => $activeGroups->count(),
            'active_groups' => $activeGroups->take(3)->values(),
            'recent_activities' => $this->buildRecentActivities($user),
        ];
    }

    private function buildSummary(Collection $groups, User $user): array
    {
        $owedToYou = 0.0;
        $youOwe = 0.0;
        $currency = $groups->first()?->base_currency ?? 'BDT';

        foreach ($groups as $group) {
            foreach ($group->expenses->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open) as $expense) {
                foreach ($expense->splits as $split) {
                    if ($split->settled_at || $split->user_id === $expense->paid_by_user_id) {
                        continue;
                    }

                    if ($expense->paid_by_user_id === $user->id) {
                        $owedToYou += (float) $split->amount;
                    }

                    if ($split->user_id === $user->id) {
                        $youOwe += (float) $split->amount;
                    }
                }
            }
        }

        return [
            'currency' => $currency,
            'net_position' => [
                'amount' => number_format(abs($owedToYou - $youOwe), 2, '.', ''),
                'type' => match (true) {
                    $owedToYou > $youOwe => 'owed_to_you',
                    $youOwe > $owedToYou => 'you_owe',
                    default => 'settled',
                },
                'label' => match (true) {
                    $owedToYou > $youOwe => 'Net owed to you',
                    $youOwe > $owedToYou => 'Net you owe',
                    default => 'You are settled',
                },
            ],
            'owed_to_you' => number_format($owedToYou, 2, '.', ''),
            'you_owe' => number_format($youOwe, 2, '.', ''),
        ];
    }

    private function buildActiveGroups(Collection $groups): Collection
    {
        return $groups
            ->filter(fn (Group $group): bool => (int) $group->expenses_count > 0)
            ->sortByDesc(fn (Group $group) => $group->latestExpense?->created_at ?? $group->created_at)
            ->values();
    }

    private function buildRecentActivities(User $user): array
    {
        return ActivityLog::query()
            ->with(['group', 'actor'])
            ->whereHas('recipients', fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->limit(4)
            ->get()
            ->map(fn (ActivityLog $activity): array => $this->activityData($activity, $user))
            ->all();
    }

    private function activityData(ActivityLog $activity, User $user): array
    {
        $position = $this->activityPosition($activity, $user);

        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'title' => $activity->title,
            'subtitle' => $activity->group?->name,
            'amount' => $position['amount'],
            'currency' => $position['currency'],
            'position_type' => $position['type'],
            'position_label' => $position['label'],
            'actor' => $activity->actor ? [
                'id' => $activity->actor->id,
                'name' => $activity->actor->name,
                'initials' => $this->initials($activity->actor->name),
            ] : null,
            'group' => $activity->group ? [
                'id' => $activity->group->id,
                'name' => $activity->group->name,
            ] : null,
            'metadata' => $activity->metadata,
            'created_at' => $activity->created_at,
        ];
    }

    private function activityPosition(ActivityLog $activity, User $user): array
    {
        $metadata = $activity->metadata ?? [];

        if ($activity->type === ActivityType::ExpenseCreated->value) {
            $isPayer = (int) ($metadata['paid_by_user_id'] ?? 0) === $user->id;
            $splits = collect($metadata['splits'] ?? []);

            if (! $isPayer && ! $splits->has($user->id)) {
                return [
                    'amount' => null,
                    'currency' => $metadata['currency'] ?? null,
                    'type' => 'info',
                    'label' => null,
                ];
            }

            $amount = $isPayer
                ? $splits
                    ->reject(fn ($amount, $userId): bool => (int) $userId === $user->id)
                    ->sum(fn ($amount): float => (float) $amount)
                : (float) ($splits[$user->id] ?? 0);

            return [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $metadata['currency'] ?? null,
                'type' => $isPayer ? 'owed_to_you' : 'you_owe',
                'label' => $isPayer ? 'You are owed' : 'You owe',
            ];
        }

        if ($activity->type === ActivityType::SettlementCreated->value) {
            return [
                'amount' => $metadata['amount'] ?? '0.00',
                'currency' => $metadata['currency'] ?? null,
                'type' => 'settled',
                'label' => 'Settled',
            ];
        }

        if ($activity->type === ActivityType::BalanceReminderSent->value) {
            return [
                'amount' => $metadata['amount'] ?? '0.00',
                'currency' => $metadata['currency'] ?? null,
                'type' => 'you_owe',
                'label' => 'Reminder',
            ];
        }

        return [
            'amount' => null,
            'currency' => null,
            'type' => 'info',
            'label' => null,
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
