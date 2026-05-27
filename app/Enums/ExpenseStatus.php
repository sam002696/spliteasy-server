<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
}
