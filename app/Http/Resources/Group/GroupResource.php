<?php

namespace App\Http\Resources\Group;

use App\Enums\ExpenseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentUserId = $request->user()?->id;
        $currentUserPosition = $this->currentUserPosition($currentUserId);
        $totalGroupSpend = $this->totalGroupSpend();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'base_currency' => $this->base_currency,
            'owner_id' => $this->owner_id,
            'members_count' => $this->whenCounted('members'),
            'expenses_count' => $this->whenCounted('expenses'),
            'expense_counts' => $this->expenseCounts(),
            'latest_expense' => $this->latestExpenseData(),
            'members_preview' => $this->membersPreview(),
            'summary' => [
                'total_group_spend' => number_format($totalGroupSpend, 2, '.', ''),
                'current_user_position' => $currentUserPosition,
                'position_percentage' => $this->positionPercentage($currentUserPosition['amount'], $totalGroupSpend),
                'settled_percentage' => $this->currentUserDirectionalSettledPercentage(
                    $currentUserId,
                    $currentUserPosition['type']
                ),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function latestExpenseData(): ?array
    {
        if (! $this->relationLoaded('latestExpense') || ! $this->latestExpense) {
            return null;
        }

        return [
            'id' => $this->latestExpense->id,
            'description' => $this->latestExpense->description,
            'amount' => $this->latestExpense->amount,
            'currency' => $this->latestExpense->currency,
            'status' => $this->latestExpense->status->value,
            'expense_date' => $this->latestExpense->expense_date,
            'paid_by' => $this->latestExpense->relationLoaded('paidBy') ? [
                'id' => $this->latestExpense->paidBy->id,
                'name' => $this->latestExpense->paidBy->name,
                'email' => $this->latestExpense->paidBy->email,
            ] : null,
            'created_at' => $this->latestExpense->created_at,
        ];
    }

    private function membersPreview(): array
    {
        if (! $this->relationLoaded('memberships')) {
            return [
                'members' => [],
                'remaining_count' => 0,
            ];
        }

        $members = $this->memberships
            ->take(4)
            ->map(fn ($membership): array => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'initials' => $this->initials($membership->user->name),
                'role' => $membership->role->value,
            ])
            ->values()
            ->all();

        return [
            'members' => $members,
            'remaining_count' => max($this->memberships->count() - count($members), 0),
        ];
    }

    private function currentUserPosition(?int $currentUserId): array
    {
        if (! $currentUserId || ! $this->relationLoaded('expenses')) {
            return [
                'amount' => '0.00',
                'type' => 'no_activity',
                'label' => 'No expenses yet',
            ];
        }

        if ($this->expenses->isEmpty()) {
            return [
                'amount' => '0.00',
                'type' => 'no_activity',
                'label' => 'No expenses yet',
            ];
        }

        $netAmount = 0.0;

        foreach ($this->expenses->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open) as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->settled_at) {
                    continue;
                }

                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                if ($expense->paid_by_user_id === $currentUserId) {
                    $netAmount += (float) $split->amount;
                }

                if ($split->user_id === $currentUserId) {
                    $netAmount -= (float) $split->amount;
                }
            }
        }

        $type = match (true) {
            $netAmount > 0 => 'owed_to_you',
            $netAmount < 0 => 'you_owe',
            default => 'settled',
        };

        return [
            'amount' => number_format(abs($netAmount), 2, '.', ''),
            'type' => $type,
            'label' => match ($type) {
                'owed_to_you' => 'You are owed',
                'you_owe' => 'You owe',
                default => 'You are settled',
            },
        ];
    }

    private function totalGroupSpend(): float
    {
        if (! $this->relationLoaded('expenses')) {
            return 0.0;
        }

        return (float) $this->expenses->sum(fn ($expense): float => (float) $expense->amount);
    }

    private function positionPercentage(string $positionAmount, float $totalGroupSpend): int
    {
        if ($totalGroupSpend <= 0) {
            return 0;
        }

        return (int) min(round(((float) $positionAmount / $totalGroupSpend) * 100), 100);
    }

    private function settledPercentage(): int
    {
        if (! $this->relationLoaded('expenses') || $this->expenses->isEmpty()) {
            return 0;
        }

        $settledExpenses = $this->expenses
            ->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Settled)
            ->count();

        return (int) round(($settledExpenses / $this->expenses->count()) * 100);
    }

    private function currentUserDirectionalSettledPercentage(?int $currentUserId, string $positionType): int
    {
        if (! $currentUserId || ! $this->relationLoaded('expenses') || $this->expenses->isEmpty()) {
            return 0;
        }

        if ($positionType === 'settled') {
            return 100;
        }

        $totalAmount = 0.0;
        $settledAmount = 0.0;

        foreach ($this->expenses as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                $isCurrentDirection = match ($positionType) {
                    'owed_to_you' => $expense->paid_by_user_id === $currentUserId,
                    'you_owe' => $split->user_id === $currentUserId,
                    default => false,
                };

                if (! $isCurrentDirection) {
                    continue;
                }

                $amount = (float) $split->amount;
                $totalAmount += $amount;

                if ($expense->status !== ExpenseStatus::Open || $split->settled_at) {
                    $settledAmount += $amount;
                }
            }
        }

        if ($totalAmount <= 0) {
            return 0;
        }

        return (int) min(round(($settledAmount / $totalAmount) * 100), 100);
    }

    private function expenseCounts(): array
    {
        if (! $this->relationLoaded('expenses')) {
            return [
                'total' => 0,
                'open' => 0,
                'settled' => 0,
            ];
        }

        return [
            'total' => $this->expenses->count(),
            'open' => $this->expenses
                ->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open)
                ->count(),
            'settled' => $this->expenses
                ->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Settled)
                ->count(),
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
