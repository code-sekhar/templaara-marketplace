<?php

namespace App\Enums;

enum ProductCurrency: string
{
    case INR = 'INR';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
}
