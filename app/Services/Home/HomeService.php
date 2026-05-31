<?php

namespace App\Services\Home;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Group;
use App\Models\Settlement;
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
        $expenses = Expense::query()
            ->with(['group', 'createdBy', 'paidBy', 'splits'])
            ->whereHas('group.members', fn ($query) => $query->where('users.id', $user->id))
            ->latest()
            ->limit(4)
            ->get()
            ->map(fn (Expense $expense): array => $this->expenseActivity($expense, $user));

        $settlements = Settlement::query()
            ->with(['group', 'paidBy', 'paidTo'])
            ->where(function ($query) use ($user): void {
                $query->where('paid_by_user_id', $user->id)
                    ->orWhere('paid_to_user_id', $user->id);
            })
            ->latest('settled_at')
            ->limit(4)
            ->get()
            ->map(fn (Settlement $settlement): array => $this->settlementActivity($settlement, $user));

        return $expenses
            ->concat($settlements)
            ->sortByDesc('created_at')
            ->take(4)
            ->values()
            ->all();
    }

    private function expenseActivity(Expense $expense, User $user): array
    {
        $isPayer = $expense->paid_by_user_id === $user->id;
        $amount = $isPayer
            ? $expense->splits
                ->filter(fn ($split): bool => $split->user_id !== $user->id && is_null($split->settled_at))
                ->sum(fn ($split): float => (float) $split->amount)
            : (float) ($expense->splits->firstWhere('user_id', $user->id)?->amount ?? 0);
        $type = $isPayer ? 'owed_to_you' : 'you_owe';

        return [
            'id' => "expense:{$expense->id}",
            'type' => 'expense_added',
            'title' => "{$expense->createdBy->name} added {$expense->description}",
            'subtitle' => $expense->group->name,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $expense->currency,
            'position_type' => $type,
            'position_label' => $type === 'owed_to_you' ? 'You are owed' : 'You owe',
            'actor' => [
                'id' => $expense->createdBy->id,
                'name' => $expense->createdBy->name,
                'initials' => $this->initials($expense->createdBy->name),
            ],
            'group' => [
                'id' => $expense->group->id,
                'name' => $expense->group->name,
            ],
            'created_at' => $expense->created_at,
        ];
    }

    private function settlementActivity(Settlement $settlement, User $user): array
    {
        $otherUser = $settlement->paid_by_user_id === $user->id
            ? $settlement->paidTo
            : $settlement->paidBy;

        return [
            'id' => "settlement:{$settlement->id}",
            'type' => 'settlement_created',
            'title' => "{$settlement->paidBy->name} settled with {$settlement->paidTo->name}",
            'subtitle' => $settlement->group->name,
            'amount' => $settlement->amount,
            'currency' => $settlement->currency,
            'position_type' => 'settled',
            'position_label' => 'Settled',
            'actor' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'initials' => $this->initials($otherUser->name),
            ],
            'group' => [
                'id' => $settlement->group->id,
                'name' => $settlement->group->name,
            ],
            'created_at' => $settlement->settled_at,
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
