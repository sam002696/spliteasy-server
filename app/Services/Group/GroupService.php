<?php

namespace App\Services\Group;

use App\Enums\GroupInvitationStatus;
use App\Enums\GroupMemberRole;
use App\Enums\ExpenseStatus;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupService
{
    public function getUserGroups(User $user): Collection
    {
        return $user->groups()
            ->withCount(['members', 'expenses'])
            ->latest('groups.created_at')
            ->get();
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

        return $group->load(['owner'])->loadCount(['members', 'expenses']);
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteGroup(Group $group, User $user): void
    {
        $this->ensureGroupOwner($group, $user);

        $group->delete();
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

            return $invitation->load(['group', 'invitedBy']);
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

        return $invitation->load(['group', 'invitedBy']);
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

        return GroupInvitation::query()->create([
            'group_id' => $group->id,
            'invited_by_user_id' => $owner->id,
            'invited_user_id' => $invitedUser->id,
            'status' => GroupInvitationStatus::Pending,
        ])->load(['group', 'invitedBy']);
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
}
