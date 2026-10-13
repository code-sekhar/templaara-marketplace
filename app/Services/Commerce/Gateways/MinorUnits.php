<?php

namespace App\Services\Commerce\Gateways;

use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;

final class MinorUnits
{
    public static function fromDecimal(string $amount, string $currency): int
    {
        if (! in_array($currency, ['INR', 'USD', 'EUR', 'GBP'], true) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $amount)) {
            throw new \InvalidArgumentException('Unsupported payment amount or currency.');
        }

        return BigDecimal::of(Money::decimal($amount))->multipliedBy(100)->toBigInteger()->toInt();
    }

    public static function toDecimal(mixed $minor, string $currency): string
    {
        if (! is_int($minor) || $minor < 0 || $minor > 99999999999999 || ! in_array($currency, ['INR', 'USD', 'EUR', 'GBP'], true)) {
            throw new \InvalidArgumentException('Invalid provider amount or currency.');
        }

        return (string) BigDecimal::of($minor)->dividedBy(100, 2);
    }
}
