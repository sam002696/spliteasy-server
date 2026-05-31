<?php

namespace App\Services\Balance;

use App\Enums\ActivityType;
use App\Enums\ExpenseStatus;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BalanceService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

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

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function sendReminder(Group $group, User $sender, User $debtor): ActivityLog
    {
        $this->ensureGroupMember($group, $sender);
        $this->ensureGroupMember($group, $debtor);

        if ($sender->id === $debtor->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot remind yourself.'],
            ]);
        }

        $amountOwed = $this->calculateAmountOwedByUser($group, $sender, $debtor);

        if ($amountOwed <= 0) {
            throw ValidationException::withMessages([
                'balance' => ['This user does not owe you in this group.'],
            ]);
        }

        return $this->activityLogService->record(
            ActivityType::BalanceReminderSent,
            "{$sender->name} reminded you to settle {$group->base_currency} " . number_format($amountOwed, 2),
            $group,
            $sender,
            [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'reminder_from_user_id' => $sender->id,
                'reminder_from_name' => $sender->name,
                'debtor_user_id' => $debtor->id,
                'debtor_name' => $debtor->name,
                'amount' => number_format($amountOwed, 2, '.', ''),
                'currency' => $group->base_currency,
            ],
            [$debtor->id]
        );
    }

    private function buildGroupBalances(Group $group, User $user): Collection
    {
        $pairBalances = [];
        $pairExpenses = [];
        $pairGrossAmounts = [];
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

                if ($otherUserId) {
                    $pairExpenses[$otherUserId][] = $expense;
                }

                if ($otherUserId && $expense->status === ExpenseStatus::Open && is_null($split->settled_at)) {
                    $pairBalances[$otherUserId] = ($pairBalances[$otherUserId] ?? 0) + $amount;
                    $pairGrossAmounts[$otherUserId] = ($pairGrossAmounts[$otherUserId] ?? 0) + abs($amount);
                }
            }
        }

        return collect($pairExpenses)
            ->map(function (array $expenses, int $otherUserId) use ($group, $pairBalances, $pairGrossAmounts, $pairUsers): array {
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
                    'settled_percentage' => $this->settledPercentage(
                        (float) ($pairGrossAmounts[$otherUserId] ?? 0),
                        $netAmount
                    ),
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

    /**
     * @throws AuthorizationException
     */
    private function ensureGroupMember(Group $group, User $user): void
    {
        $isMember = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('User is not a member of this group.');
        }
    }

    private function calculateAmountOwedByUser(Group $group, User $owedToUser, User $debtor): float
    {
        $group->loadMissing(['expenses.splits']);

        $amount = 0.0;

        foreach ($group->expenses->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open) as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->settled_at || $split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                if ($expense->paid_by_user_id === $owedToUser->id && $split->user_id === $debtor->id) {
                    $amount += (float) $split->amount;
                }

                if ($expense->paid_by_user_id === $debtor->id && $split->user_id === $owedToUser->id) {
                    $amount -= (float) $split->amount;
                }
            }
        }

        return round($amount, 2);
    }

    private function settledPercentage(float $grossAmount, float $netAmount): int
    {
        if ($grossAmount <= 0) {
            return round($netAmount, 2) === 0.0 ? 100 : 0;
        }

        $settledAmount = max($grossAmount - abs($netAmount), 0);

        return (int) min(round(($settledAmount / $grossAmount) * 100), 100);
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
