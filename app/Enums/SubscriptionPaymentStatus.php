<?php

namespace App\Enums;

enum SubscriptionPaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
}
