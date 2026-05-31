<?php

namespace App\Enums;

enum ActivityType: string
{
    case GroupCreated = 'group.created';
    case GroupDeleted = 'group.deleted';
    case GroupMemberLeft = 'group.member.left';
    case GroupMemberRemoved = 'group.member.removed';
    case GroupInvitationSent = 'group.invitation.sent';
    case GroupInvitationAccepted = 'group.invitation.accepted';
    case GroupInvitationRejected = 'group.invitation.rejected';
    case ExpenseCreated = 'expense.created';
    case SettlementCreated = 'settlement.created';
}
