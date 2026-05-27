<?php

namespace App\Services\Expense;

use App\Enums\ExpenseSplitMethod;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    /**
     * @throws AuthorizationException
     */
    public function getGroupExpenses(Group $group, User $user): Collection
    {
        $this->ensureGroupMember($group, $user);

        return $group->expenses()
            ->with(['paidBy', 'createdBy', 'splits.user'])
            ->latest('expense_date')
            ->latest()
            ->get();
    }

    /**
     * @throws AuthorizationException
     */
    public function getExpenseDetails(Expense $expense, User $user): Expense
    {
        $this->ensureGroupMember($expense->group, $user);

        return $expense->load(['group', 'paidBy', 'createdBy', 'splits.user']);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createExpense(Group $group, User $creator, array $data): Expense
    {
        $this->ensureGroupMember($group, $creator);

        $splitMethod = ExpenseSplitMethod::from($data['split_method'] ?? ExpenseSplitMethod::Equal->value);

        if ($splitMethod !== ExpenseSplitMethod::Equal) {
            throw ValidationException::withMessages([
                'split_method' => ['Only equal split is supported right now.'],
            ]);
        }

        $paidByUserId = (int) $data['paid_by_user_id'];
        $this->ensureUserIsGroupMember($group, $paidByUserId, 'paid_by_user_id');

        $participantUserIds = $this->resolveParticipantUserIds($group, $data['participant_user_ids'] ?? null);

        return DB::transaction(function () use ($group, $creator, $data, $splitMethod, $paidByUserId, $participantUserIds): Expense {
            $expense = Expense::query()->create([
                'group_id' => $group->id,
                'created_by_user_id' => $creator->id,
                'paid_by_user_id' => $paidByUserId,
                'description' => $data['description'],
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'currency' => strtoupper($data['currency'] ?? $group->base_currency),
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'split_method' => $splitMethod,
                'status' => ExpenseStatus::Open,
            ]);

            foreach ($this->calculateEqualSplits((float) $data['amount'], $participantUserIds) as $userId => $amount) {
                ExpenseSplit::query()->create([
                    'expense_id' => $expense->id,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'share_value' => 1,
                ]);
            }

            return $expense->load(['paidBy', 'createdBy', 'splits.user']);
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteExpense(Expense $expense, User $user): void
    {
        $group = $expense->group;
        $this->ensureGroupMember($group, $user);

        if ($expense->created_by_user_id !== $user->id && $group->owner_id !== $user->id) {
            throw new AuthorizationException('Only the expense creator or group owner can delete this expense.');
        }

        $expense->delete();
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGroupMember(Group $group, User $user): void
    {
        if (! $this->isGroupMember($group, $user->id)) {
            throw new AuthorizationException('You are not a member of this group.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureUserIsGroupMember(Group $group, int $userId, string $field): void
    {
        if (! $this->isGroupMember($group, $userId)) {
            throw ValidationException::withMessages([
                $field => ['Selected user is not a member of this group.'],
            ]);
        }
    }

    private function isGroupMember(Group $group, int $userId): bool
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * @throws ValidationException
     *
     * @return list<int>
     */
    private function resolveParticipantUserIds(Group $group, ?array $participantUserIds): array
    {
        if (! $participantUserIds) {
            return GroupMember::query()
                ->where('group_id', $group->id)
                ->pluck('user_id')
                ->map(fn ($userId): int => (int) $userId)
                ->values()
                ->all();
        }

        $participantUserIds = array_values(array_unique(array_map('intval', $participantUserIds)));

        foreach ($participantUserIds as $userId) {
            $this->ensureUserIsGroupMember($group, $userId, 'participant_user_ids');
        }

        return $participantUserIds;
    }

    /**
     * @param  list<int>  $participantUserIds
     * @return array<int, string>
     */
    private function calculateEqualSplits(float $amount, array $participantUserIds): array
    {
        $totalCents = (int) round($amount * 100);
        $memberCount = count($participantUserIds);
        $baseShare = intdiv($totalCents, $memberCount);
        $remainder = $totalCents % $memberCount;
        $splits = [];

        foreach ($participantUserIds as $index => $userId) {
            $shareInCents = $baseShare + ($index < $remainder ? 1 : 0);
            $splits[$userId] = number_format($shareInCents / 100, 2, '.', '');
        }

        return $splits;
    }
}
