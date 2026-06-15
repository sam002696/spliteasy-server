<?php

namespace App\Services\Expense;

use App\Enums\ActivityType;
use App\Enums\ExpenseSplitMethod;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

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
        $paidByUserId = (int) $data['paid_by_user_id'];
        $this->ensureUserIsGroupMember($group, $paidByUserId, 'paid_by_user_id');
        $splitRows = $this->calculateSplits($group, $splitMethod, (float) $data['amount'], $data);

        return DB::transaction(function () use ($group, $creator, $data, $splitMethod, $paidByUserId, $splitRows): Expense {
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

            foreach ($splitRows as $splitRow) {
                ExpenseSplit::query()->create([
                    'expense_id' => $expense->id,
                    'user_id' => $splitRow['user_id'],
                    'amount' => $splitRow['amount'],
                    'share_value' => $splitRow['share_value'],
                ]);
            }

            $expense = $expense->load(['paidBy', 'createdBy', 'splits.user']);

            $this->activityLogService->record(
                ActivityType::ExpenseCreated,
                "{$creator->name} added {$expense->description}",
                $group,
                $creator,
                [
                    'expense_id' => $expense->id,
                    'description' => $expense->description,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'paid_by_user_id' => $expense->paid_by_user_id,
                    'paid_by_name' => $expense->paidBy->name,
                    'splits' => $expense->splits
                        ->mapWithKeys(fn ($split): array => [
                            $split->user_id => $split->amount,
                        ])
                        ->all(),
                ],
                $this->activityLogService->groupMemberIds($group)
            );

            return $expense;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function deleteExpense(Expense $expense, User $user): void
    {
        $group = $expense->group;
        $this->ensureGroupMember($group, $user);

        if ($expense->created_by_user_id !== $user->id && $group->owner_id !== $user->id) {
            throw new AuthorizationException('Only the expense creator or group owner can delete this expense.');
        }

        if ($expense->status === ExpenseStatus::Settled) {
            throw ValidationException::withMessages([
                'expense' => ['You cannot delete an expense that has been settled.'],
            ]);
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

    /**
     * @return list<array{user_id:int, amount:string, share_value:string|null}>
     *
     * @throws ValidationException
     */
    private function calculateSplits(Group $group, ExpenseSplitMethod $splitMethod, float $amount, array $data): array
    {
        return match ($splitMethod) {
            ExpenseSplitMethod::Equal => $this->calculateEqualSplitRows(
                $this->resolveParticipantUserIds($group, $data['participant_user_ids'] ?? null),
                $amount
            ),
            ExpenseSplitMethod::Custom => $this->calculateCustomSplitRows($group, $amount, $data['splits'] ?? []),
            ExpenseSplitMethod::Percent => $this->calculateWeightedSplitRows(
                $group,
                $amount,
                $data['splits'] ?? [],
                ExpenseSplitMethod::Percent
            ),
            ExpenseSplitMethod::Shares => $this->calculateWeightedSplitRows(
                $group,
                $amount,
                $data['splits'] ?? [],
                ExpenseSplitMethod::Shares
            ),
        };
    }

    /**
     * @param  list<int>  $participantUserIds
     * @return list<array{user_id:int, amount:string, share_value:string}>
     */
    private function calculateEqualSplitRows(array $participantUserIds, float $amount): array
    {
        return collect($this->calculateEqualSplits($amount, $participantUserIds))
            ->map(fn (string $splitAmount, int $userId): array => [
                'user_id' => $userId,
                'amount' => $splitAmount,
                'share_value' => '1.00',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{user_id:int, amount:string, share_value:string}>
     *
     * @throws ValidationException
     */
    private function calculateCustomSplitRows(Group $group, float $amount, array $splits): array
    {
        $this->validateSplitRows($group, $splits, 'amount');

        $totalCents = $this->toCents($amount);
        $splitRows = collect($splits)->map(function (array $split): array {
            $amountInCents = $this->toCents((float) $split['amount']);

            return [
                'user_id' => (int) $split['user_id'],
                'amount_cents' => $amountInCents,
                'share_value' => number_format($amountInCents / 100, 2, '.', ''),
            ];
        });

        if ($splitRows->sum('amount_cents') !== $totalCents) {
            throw ValidationException::withMessages([
                'splits' => ['Custom split amounts must add up to the expense amount.'],
            ]);
        }

        return $splitRows
            ->map(fn (array $split): array => [
                'user_id' => $split['user_id'],
                'amount' => number_format($split['amount_cents'] / 100, 2, '.', ''),
                'share_value' => $split['share_value'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{user_id:int, amount:string, share_value:string}>
     *
     * @throws ValidationException
     */
    private function calculateWeightedSplitRows(
        Group $group,
        float $amount,
        array $splits,
        ExpenseSplitMethod $splitMethod
    ): array {
        $this->validateSplitRows($group, $splits, 'value');

        $weights = collect($splits)->map(fn (array $split): array => [
            'user_id' => (int) $split['user_id'],
            'value' => (float) $split['value'],
        ]);
        $totalWeight = $weights->sum('value');

        if ($splitMethod === ExpenseSplitMethod::Percent && abs($totalWeight - 100.0) > 0.01) {
            throw ValidationException::withMessages([
                'splits' => ['Percent splits must add up to 100.'],
            ]);
        }

        if ($totalWeight <= 0) {
            throw ValidationException::withMessages([
                'splits' => ['Split values must add up to more than zero.'],
            ]);
        }

        $totalCents = $this->toCents($amount);
        $allocatedCents = 0;
        $rows = $weights
            ->values()
            ->map(function (array $split, int $index) use ($weights, $totalWeight, $totalCents, &$allocatedCents): array {
                $isLast = $index === $weights->count() - 1;
                $amountInCents = $isLast
                    ? $totalCents - $allocatedCents
                    : (int) floor(($totalCents * $split['value']) / $totalWeight);
                $allocatedCents += $amountInCents;

                return [
                    'user_id' => $split['user_id'],
                    'amount' => number_format($amountInCents / 100, 2, '.', ''),
                    'share_value' => number_format($split['value'], 2, '.', ''),
                ];
            });

        return $rows->all();
    }

    /**
     * @throws ValidationException
     */
    private function validateSplitRows(Group $group, array $splits, string $valueKey): void
    {
        if (empty($splits)) {
            throw ValidationException::withMessages([
                'splits' => ['At least one split is required.'],
            ]);
        }

        $seenUserIds = [];

        foreach ($splits as $index => $split) {
            $fieldPrefix = "splits.{$index}";

            if (! isset($split['user_id'])) {
                throw ValidationException::withMessages([
                    "{$fieldPrefix}.user_id" => ['A split user is required.'],
                ]);
            }

            $userId = (int) $split['user_id'];

            if (in_array($userId, $seenUserIds, true)) {
                throw ValidationException::withMessages([
                    "{$fieldPrefix}.user_id" => ['Each split user must be unique.'],
                ]);
            }

            $this->ensureUserIsGroupMember($group, $userId, "{$fieldPrefix}.user_id");
            $seenUserIds[] = $userId;

            if (! isset($split[$valueKey]) || (float) $split[$valueKey] <= 0) {
                throw ValidationException::withMessages([
                    "{$fieldPrefix}.{$valueKey}" => ['Split values must be greater than zero.'],
                ]);
            }
        }
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
