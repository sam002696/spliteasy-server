<?php

namespace App\Services\Balance;

use App\Enums\ExpenseStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BalanceService
{
    /**
     * @throws ValidationException
     */
    public function getUserBalances(User $user, string $filter = 'open'): array
    {
        $filter = strtolower($filter);

        if (! in_array($filter, ['open', 'you_owe', 'settled'], true)) {
            throw ValidationException::withMessages([
                'filter' => ['Invalid balance filter. Allowed values are open, you_owe, settled.'],
            ]);
        }

        $groups = $user->groups()
            ->with(['members', 'expenses.paidBy', 'expenses.splits.user'])
            ->latest('groups.created_at')
            ->get();

        $balances = $groups
            ->flatMap(fn (Group $group): Collection => $this->buildGroupBalances($group, $user))
            ->filter(fn (array $balance): bool => $this->matchesFilter($balance, $filter))
            ->values();

        $allBalances = $groups
            ->flatMap(fn (Group $group): Collection => $this->buildGroupBalances($group, $user))
            ->values();

        return [
            'filter' => $filter,
            'counts' => [
                'open' => $allBalances->filter(fn (array $balance): bool => $balance['type'] !== 'settled')->count(),
                'you_owe' => $allBalances->filter(fn (array $balance): bool => $balance['type'] === 'you_owe')->count(),
                'settled' => $allBalances->filter(fn (array $balance): bool => $balance['type'] === 'settled')->count(),
            ],
            'balances' => $balances->all(),
        ];
    }

    private function buildGroupBalances(Group $group, User $user): Collection
    {
        $pairBalances = [];
        $pairExpenses = [];
        $pairUsers = [];

        foreach ($group->expenses as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                $otherUserId = null;
                $amount = 0.0;

                if ($expense->paid_by_user_id === $user->id) {
                    $otherUserId = $split->user_id;
                    $pairUsers[$otherUserId] = $split->user;
                    $amount = (float) $split->amount;
                }

                if ($split->user_id === $user->id) {
                    $otherUserId = $expense->paid_by_user_id;
                    $pairUsers[$otherUserId] = $expense->paidBy;
                    $amount = -((float) $split->amount);
                }

                if (! $otherUserId) {
                    continue;
                }

                $pairBalances[$otherUserId] = ($pairBalances[$otherUserId] ?? 0) + (
                    $expense->status === ExpenseStatus::Open ? $amount : 0
                );
                $pairExpenses[$otherUserId][] = $expense;
            }
        }

        return collect($pairExpenses)
            ->map(function (array $expenses, int $otherUserId) use ($group, $pairBalances, $pairUsers): array {
                $otherUser = $pairUsers[$otherUserId] ?? $group->members->firstWhere('id', $otherUserId);
                $netAmount = round($pairBalances[$otherUserId] ?? 0, 2);
                $type = match (true) {
                    $netAmount > 0 => 'owed_to_you',
                    $netAmount < 0 => 'you_owe',
                    default => 'settled',
                };
                $latestExpense = collect($expenses)->sortByDesc('expense_date')->first();

                return [
                    'id' => "{$group->id}:{$otherUserId}",
                    'group' => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'category' => $group->category,
                        'base_currency' => $group->base_currency,
                    ],
                    'user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'email' => $otherUser->email,
                        'initials' => $this->initials($otherUser->name),
                    ],
                    'amount' => number_format(abs($netAmount), 2, '.', ''),
                    'currency' => $group->base_currency,
                    'type' => $type,
                    'label' => $this->label($type, $otherUser->name),
                    'latest_expense' => $latestExpense ? [
                        'id' => $latestExpense->id,
                        'description' => $latestExpense->description,
                        'amount' => $latestExpense->amount,
                        'currency' => $latestExpense->currency,
                        'expense_date' => $latestExpense->expense_date,
                        'status' => $latestExpense->status->value,
                    ] : null,
                    'settled_percentage' => $this->settledPercentage($expenses),
                    'action' => match ($type) {
                        'owed_to_you' => 'remind',
                        'you_owe' => 'mark_settled',
                        default => null,
                    },
                ];
            })
            ->sortByDesc(fn (array $balance): float => (float) $balance['amount'])
            ->values();
    }

    private function matchesFilter(array $balance, string $filter): bool
    {
        return match ($filter) {
            'open' => $balance['type'] !== 'settled',
            'you_owe' => $balance['type'] === 'you_owe',
            'settled' => $balance['type'] === 'settled',
        };
    }

    private function settledPercentage(array $expenses): int
    {
        if (count($expenses) === 0) {
            return 0;
        }

        $settledCount = collect($expenses)
            ->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Settled)
            ->count();

        return (int) round(($settledCount / count($expenses)) * 100);
    }

    private function label(string $type, string $name): string
    {
        return match ($type) {
            'owed_to_you' => "{$name} owes you",
            'you_owe' => "You owe {$name}",
            default => "Settled with {$name}",
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
