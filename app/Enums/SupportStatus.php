<?php

namespace App\Enums;

enum SupportStatus: string
{
    case AwaitingSeller = 'awaiting_seller';
    case AwaitingCustomer = 'awaiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function active(): bool
    {
        return in_array($this, [self::AwaitingSeller, self::AwaitingCustomer]);
    }
}
