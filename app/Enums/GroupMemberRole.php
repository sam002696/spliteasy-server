<?php

namespace App\Enums;

enum GroupMemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
