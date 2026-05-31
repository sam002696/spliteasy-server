<?php

namespace App\Services\Settlement;

use App\Enums\ActivityType;
use App\Enums\ExpenseStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function markBalanceSettled(Group $group, User $currentUser, User $paidToUser): Settlement
    {
        $this->ensureGroupMember($group, $currentUser);
        $this->ensureGroupMember($group, $paidToUser);

        if ($currentUser->id === $paidToUser->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot settle a balance with yourself.'],
            ]);
        }

        $netAmount = $this->calculateCurrentUserNetAmount($group, $currentUser, $paidToUser);

        if ($netAmount >= 0) {
            throw ValidationException::withMessages([
                'balance' => ['You do not owe this user in this group.'],
            ]);
        }

        return DB::transaction(function () use ($group, $currentUser, $paidToUser, $netAmount): Settlement {
            $settlement = Settlement::query()->create([
                'group_id' => $group->id,
                'created_by_user_id' => $currentUser->id,
                'paid_by_user_id' => $currentUser->id,
                'paid_to_user_id' => $paidToUser->id,
                'amount' => number_format(abs($netAmount), 2, '.', ''),
                'currency' => $group->base_currency,
                'settled_at' => now(),
            ]);

            $this->markCoveredExpenseSplitsAsSettled($group, $currentUser, $paidToUser, (float) $settlement->amount);

            $settlement = $settlement->load(['group', 'paidBy', 'paidTo']);

            $this->activityLogService->record(
                ActivityType::SettlementCreated,
                "{$currentUser->name} settled with {$paidToUser->name}",
                $group,
                $currentUser,
                [
                    'settlement_id' => $settlement->id,
                    'paid_by_user_id' => $currentUser->id,
                    'paid_by_name' => $currentUser->name,
                    'paid_to_user_id' => $paidToUser->id,
                    'paid_to_name' => $paidToUser->name,
                    'amount' => $settlement->amount,
                    'currency' => $settlement->currency,
                ],
                [$currentUser->id, $paidToUser->id]
            );

            return $settlement;
        });
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

    private function calculateCurrentUserNetAmount(Group $group, User $currentUser, User $otherUser): float
    {
        $group->loadMissing(['expenses.splits']);

        $netAmount = 0.0;

        foreach ($group->expenses->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open) as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->settled_at) {
                    continue;
                }

                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                if ($expense->paid_by_user_id === $currentUser->id && $split->user_id === $otherUser->id) {
                    $netAmount += (float) $split->amount;
                }

                if ($expense->paid_by_user_id === $otherUser->id && $split->user_id === $currentUser->id) {
                    $netAmount -= (float) $split->amount;
                }
            }
        }

        return round($netAmount, 2);
    }

    private function markCoveredExpenseSplitsAsSettled(Group $group, User $currentUser, User $paidToUser, float $settlementAmount): void
    {
        $remainingAmountInCents = (int) round($settlementAmount * 100);

        $expenses = $group->expenses()
            ->with('splits')
            ->where('status', ExpenseStatus::Open->value)
            ->where('paid_by_user_id', $paidToUser->id)
            ->oldest('expense_date')
            ->oldest()
            ->get();

        foreach ($expenses as $expense) {
            $split = $expense->splits
                ->filter(fn ($split): bool => $split->user_id === $currentUser->id && is_null($split->settled_at))
                ->first();

            if (! $split) {
                continue;
            }

            $splitAmountInCents = (int) round(((float) $split->amount) * 100);

            if ($remainingAmountInCents < $splitAmountInCents) {
                break;
            }

            $split->update([
                'settled_at' => now(),
            ]);

            $remainingAmountInCents -= $splitAmountInCents;
            $this->markExpenseSettledIfFullyPaid($expense);
        }
    }

    private function markExpenseSettledIfFullyPaid($expense): void
    {
        $hasOpenNonPayerSplits = $expense->splits()
            ->where('user_id', '!=', $expense->paid_by_user_id)
            ->whereNull('settled_at')
            ->exists();

        if (! $hasOpenNonPayerSplits) {
            $expense->update([
                'status' => ExpenseStatus::Settled,
            ]);
        }
    }
}
