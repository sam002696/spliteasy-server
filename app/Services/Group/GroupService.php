<?php

namespace App\Services\Group;

use App\Enums\ActivityType;
use App\Enums\ExpenseStatus;
use App\Enums\GroupInvitationStatus;
use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

    /**
     * @throws ValidationException
     */
    public function getUserGroups(User $user, string $filter = 'all'): \Illuminate\Support\Collection
    {
        $groups = $user->groups()
            ->with(['latestExpense.paidBy', 'memberships.user', 'expenses.splits'])
            ->withCount(['members', 'expenses'])
            ->latest('groups.created_at')
            ->get();

        return $this->filterGroupsByUserPosition($groups, $user, $filter);
    }

    public function createGroup(User $owner, array $data): Group
    {
        return DB::transaction(function () use ($owner, $data): Group {
            $group = Group::query()->create([
                'owner_id' => $owner->id,
                'name' => $data['name'],
                'category' => $data['category'],
                'base_currency' => strtoupper($data['base_currency']),
            ]);

            GroupMember::query()->create([
                'group_id' => $group->id,
                'user_id' => $owner->id,
                'role' => GroupMemberRole::Owner,
                'joined_at' => now(),
            ]);

            $this->activityLogService->record(
                ActivityType::GroupCreated,
                "{$owner->name} created {$group->name}",
                $group,
                $owner,
                [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'category' => $group->category,
                ],
                [$owner]
            );

            foreach ($data['member_emails'] ?? [] as $email) {
                if (strtolower($email) !== strtolower($owner->email)) {
                    $this->createInvitationByEmail($group, $owner, $email);
                }
            }

            return $group->loadCount(['members', 'expenses']);
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function getGroupDetails(Group $group, User $user): Group
    {
        $this->ensureGroupMember($group, $user);

        return $group
            ->load(['owner', 'latestExpense.paidBy', 'memberships.user', 'expenses.splits'])
            ->loadCount(['members', 'expenses']);
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteGroup(Group $group, User $user): void
    {
        $this->ensureGroupOwner($group, $user);

        DB::transaction(function () use ($group, $user): void {
            $this->activityLogService->record(
                ActivityType::GroupDeleted,
                "{$user->name} deleted {$group->name}",
                $group,
                $user,
                [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                ],
                $this->activityLogService->groupMemberIds($group)
            );

            $group->delete();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function leaveGroup(Group $group, User $user): void
    {
        if ($group->owner_id === $user->id) {
            throw ValidationException::withMessages([
                'group' => ['Group owner cannot leave the group. Delete the group instead.'],
            ]);
        }

        $membership = $this->getMembership($group, $user);

        if (! $membership) {
            throw new AuthorizationException('You are not a member of this group.');
        }

        $this->activityLogService->record(
            ActivityType::GroupMemberLeft,
            "{$user->name} left {$group->name}",
            $group,
            $user,
            [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ],
            $this->activityLogService->groupMemberIds($group)
        );

        $membership->delete();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function removeMember(Group $group, User $owner, int $memberId): void
    {
        $this->ensureGroupOwner($group, $owner);

        if ($group->owner_id === $memberId) {
            throw ValidationException::withMessages([
                'member' => ['Group owner cannot be removed from the group.'],
            ]);
        }

        $membership = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $memberId)
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => ['User is not a member of this group.'],
            ]);
        }

        $this->ensureMemberHasNoUnsettledBalances($group, $memberId);

        $removedUser = User::query()->findOrFail($memberId);

        $this->activityLogService->record(
            ActivityType::GroupMemberRemoved,
            "{$removedUser->name} was removed from {$group->name}",
            $group,
            $owner,
            [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'removed_user_id' => $removedUser->id,
                'removed_user_name' => $removedUser->name,
            ],
            $this->activityLogService->groupMemberIds($group)
        );

        $membership->delete();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function inviteMember(Group $group, User $owner, string $email): GroupInvitation
    {
        $this->ensureGroupOwner($group, $owner);

        return $this->createInvitationByEmail($group, $owner, $email);
    }

    public function getPendingInvitations(User $user): Collection
    {
        return GroupInvitation::query()
            ->with(['group', 'invitedBy'])
            ->where('invited_user_id', $user->id)
            ->where('status', GroupInvitationStatus::Pending->value)
            ->latest()
            ->get();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function acceptInvitation(GroupInvitation $invitation, User $user): GroupInvitation
    {
        $this->ensureInvitationReceiver($invitation, $user);
        $this->ensurePendingInvitation($invitation);

        return DB::transaction(function () use ($invitation): GroupInvitation {
            $invitation->update([
                'status' => GroupInvitationStatus::Accepted,
                'responded_at' => now(),
            ]);

            GroupMember::query()->firstOrCreate([
                'group_id' => $invitation->group_id,
                'user_id' => $invitation->invited_user_id,
            ], [
                'role' => GroupMemberRole::Member,
                'joined_at' => now(),
            ]);

            $invitation = $invitation->load(['group', 'invitedBy', 'invitedUser']);

            $this->activityLogService->record(
                ActivityType::GroupInvitationAccepted,
                "{$invitation->invitedUser->name} joined {$invitation->group->name}",
                $invitation->group,
                $invitation->invitedUser,
                [
                    'group_id' => $invitation->group_id,
                    'group_name' => $invitation->group->name,
                    'invitation_id' => $invitation->id,
                    'user_id' => $invitation->invited_user_id,
                    'user_name' => $invitation->invitedUser->name,
                ],
                $this->activityLogService->groupMemberIds($invitation->group)
            );

            return $invitation;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rejectInvitation(GroupInvitation $invitation, User $user): GroupInvitation
    {
        $this->ensureInvitationReceiver($invitation, $user);
        $this->ensurePendingInvitation($invitation);

        $invitation->update([
            'status' => GroupInvitationStatus::Rejected,
            'responded_at' => now(),
        ]);

        $invitation = $invitation->load(['group', 'invitedBy', 'invitedUser']);

        $this->activityLogService->record(
            ActivityType::GroupInvitationRejected,
            "{$invitation->invitedUser->name} rejected {$invitation->group->name} invitation",
            $invitation->group,
            $invitation->invitedUser,
            [
                'group_id' => $invitation->group_id,
                'group_name' => $invitation->group->name,
                'invitation_id' => $invitation->id,
                'user_id' => $invitation->invited_user_id,
                'user_name' => $invitation->invitedUser->name,
            ],
            [$invitation->invited_by_user_id, $invitation->invited_user_id]
        );

        return $invitation;
    }

    /**
     * @throws AuthorizationException
     */
    public function getGroupMembers(Group $group, User $user): Collection
    {
        $this->ensureGroupMember($group, $user);

        return $group->memberships()
            ->with('user')
            ->orderByRaw("role = 'owner' desc")
            ->latest('joined_at')
            ->get();
    }

    /**
     * @throws AuthorizationException
     */
    public function getBalancesSummary(Group $group, User $user): array
    {
        $this->ensureGroupMember($group, $user);

        $openExpenses = $group->expenses()
            ->with(['paidBy', 'splits.user'])
            ->where('status', ExpenseStatus::Open->value)
            ->get();

        $pairwiseBalances = [];

        foreach ($openExpenses as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->settled_at) {
                    continue;
                }

                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                $amount = (float) $split->amount;

                if ($expense->paid_by_user_id === $user->id) {
                    $pairwiseBalances[$split->user_id] = ($pairwiseBalances[$split->user_id] ?? 0) + $amount;
                }

                if ($split->user_id === $user->id) {
                    $pairwiseBalances[$expense->paid_by_user_id] = ($pairwiseBalances[$expense->paid_by_user_id] ?? 0) - $amount;
                }
            }
        }

        $members = $group->members()->get()->keyBy('id');
        $balances = collect($pairwiseBalances)
            ->filter(fn (float $amount): bool => round($amount, 2) !== 0.0)
            ->map(function (float $amount, int $userId) use ($members): array {
                $member = $members->get($userId);

                return [
                    'user' => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                    ],
                    'amount' => number_format(abs($amount), 2, '.', ''),
                    'type' => $amount > 0 ? 'owed_to_you' : 'you_owe',
                    'label' => $amount > 0
                        ? "{$member->name} owes you"
                        : "You owe {$member->name}",
                ];
            })
            ->values()
            ->all();

        $currentUserNetAmount = collect($pairwiseBalances)->sum();
        $currentUserType = match (true) {
            $currentUserNetAmount > 0 => 'owed_to_you',
            $currentUserNetAmount < 0 => 'you_owe',
            default => 'settled',
        };

        return [
            'group_id' => $group->id,
            'base_currency' => $group->base_currency,
            'total_group_spend' => number_format((float) $group->expenses()->sum('amount'), 2, '.', ''),
            'open_unsettled_count' => count($balances),
            'current_user_position' => [
                'amount' => number_format(abs($currentUserNetAmount), 2, '.', ''),
                'type' => $currentUserType,
                'label' => match ($currentUserType) {
                    'owed_to_you' => 'You are owed',
                    'you_owe' => 'You owe',
                    default => 'You are settled',
                },
            ],
            'balances' => $balances,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function createInvitationByEmail(Group $group, User $owner, string $email): GroupInvitation
    {
        $invitedUser = User::query()
            ->where('email', strtolower($email))
            ->first();

        if (! $invitedUser) {
            throw ValidationException::withMessages([
                'email' => ['This email does not belong to a registered user.'],
            ]);
        }

        return $this->createInvitation($group, $owner, $invitedUser);
    }

    /**
     * @throws ValidationException
     */
    private function createInvitation(Group $group, User $owner, User $invitedUser): GroupInvitation
    {
        if ($owner->id === $invitedUser->id) {
            throw ValidationException::withMessages([
                'email' => ['You cannot invite yourself to your own group.'],
            ]);
        }

        if ($this->isGroupMember($group, $invitedUser->id)) {
            throw ValidationException::withMessages([
                'email' => ['User is already a member of this group.'],
            ]);
        }

        $pendingInvitation = GroupInvitation::query()
            ->where('group_id', $group->id)
            ->where('invited_user_id', $invitedUser->id)
            ->where('status', GroupInvitationStatus::Pending->value)
            ->first();

        if ($pendingInvitation) {
            throw ValidationException::withMessages([
                'email' => ['User already has a pending invitation for this group.'],
            ]);
        }

        $invitation = GroupInvitation::query()->create([
            'group_id' => $group->id,
            'invited_by_user_id' => $owner->id,
            'invited_user_id' => $invitedUser->id,
            'status' => GroupInvitationStatus::Pending,
        ])->load(['group', 'invitedBy']);

        $this->activityLogService->record(
            ActivityType::GroupInvitationSent,
            "{$owner->name} invited {$invitedUser->name} to {$group->name}",
            $group,
            $owner,
            [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'invitation_id' => $invitation->id,
                'invited_user_id' => $invitedUser->id,
                'invited_user_name' => $invitedUser->name,
            ],
            [$owner->id, $invitedUser->id]
        );

        return $invitation;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGroupOwner(Group $group, User $user): void
    {
        if ($group->owner_id !== $user->id) {
            throw new AuthorizationException('Only the group owner can perform this action.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGroupMember(Group $group, User $user): void
    {
        if (! $this->getMembership($group, $user)) {
            throw new AuthorizationException('You are not a member of this group.');
        }
    }

    private function getMembership(Group $group, User $user): ?GroupMember
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->first();
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
     */
    private function ensureMemberHasNoUnsettledBalances(Group $group, int $memberId): void
    {
        $hasUnsettledBalances = $group->expenses()
            ->where('status', ExpenseStatus::Open->value)
            ->where(function ($query) use ($memberId): void {
                $query
                    ->where(function ($query) use ($memberId): void {
                        $query
                            ->where('paid_by_user_id', $memberId)
                            ->whereHas('splits', function ($query) use ($memberId): void {
                                $query
                                    ->where('user_id', '!=', $memberId)
                                    ->whereNull('settled_at');
                            });
                    })
                    ->orWhereHas('splits', function ($query) use ($memberId): void {
                        $query
                            ->where('user_id', $memberId)
                            ->whereNull('settled_at')
                            ->whereColumn('expense_splits.user_id', '!=', 'expenses.paid_by_user_id');
                    });
            })
            ->exists();

        if ($hasUnsettledBalances) {
            throw ValidationException::withMessages([
                'member' => ['This member has unsettled balances and cannot be removed yet.'],
            ]);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureInvitationReceiver(GroupInvitation $invitation, User $user): void
    {
        if ($invitation->invited_user_id !== $user->id) {
            throw new AuthorizationException('This invitation does not belong to you.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensurePendingInvitation(GroupInvitation $invitation): void
    {
        if ($invitation->status !== GroupInvitationStatus::Pending) {
            throw ValidationException::withMessages([
                'invitation' => ['This invitation has already been handled.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function filterGroupsByUserPosition(Collection $groups, User $user, string $filter): \Illuminate\Support\Collection
    {
        $filter = strtolower($filter);

        if (! in_array($filter, ['all', 'owed_to_you', 'you_owe', 'settled'], true)) {
            throw ValidationException::withMessages([
                'filter' => ['Invalid group filter. Allowed values are all, owed_to_you, you_owe, settled.'],
            ]);
        }

        if ($filter === 'all') {
            return $groups;
        }

        return $groups
            ->filter(function (Group $group) use ($user, $filter): bool {
                $netAmount = $this->calculateCurrentUserNetAmount($group, $user);
                $hasExpenses = $group->relationLoaded('expenses') && $group->expenses->isNotEmpty();

                return match ($filter) {
                    'owed_to_you' => $netAmount > 0,
                    'you_owe' => $netAmount < 0,
                    'settled' => $hasExpenses && round($netAmount, 2) === 0.0,
                };
            })
            ->values();
    }

    private function calculateCurrentUserNetAmount(Group $group, User $user): float
    {
        if (! $group->relationLoaded('expenses')) {
            return 0.0;
        }

        $netAmount = 0.0;

        foreach ($group->expenses->filter(fn ($expense): bool => $expense->status === ExpenseStatus::Open) as $expense) {
            foreach ($expense->splits as $split) {
                if ($split->settled_at) {
                    continue;
                }

                if ($split->user_id === $expense->paid_by_user_id) {
                    continue;
                }

                if ($expense->paid_by_user_id === $user->id) {
                    $netAmount += (float) $split->amount;
                }

                if ($split->user_id === $user->id) {
                    $netAmount -= (float) $split->amount;
                }
            }
        }

        return $netAmount;
    }
}
