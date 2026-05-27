<?php

namespace App\Enums;

enum ExpenseSplitMethod: string
{
    case Equal = 'equal';
    case Custom = 'custom';
    case Percent = 'percent';
    case Shares = 'shares';
}
