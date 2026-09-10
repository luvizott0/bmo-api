<?php

namespace App\Enums;

enum BankAccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Investment = 'investment';
    case Cash = 'cash';
    case Other = 'other';
}
