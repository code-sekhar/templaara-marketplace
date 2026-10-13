<?php

namespace App\Services\Commerce;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function decimal(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::Unnecessary);
    }

    /** @param iterable<string> $amounts */
    public static function sum(iterable $amounts): string
    {
        $sum = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->plus(self::decimal($amount));
        }

        return (string) $sum->toScale(2, RoundingMode::Unnecessary);
    }
}
