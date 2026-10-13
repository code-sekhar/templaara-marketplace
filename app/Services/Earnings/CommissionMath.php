<?php

namespace App\Services\Earnings;

use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class CommissionMath
{
    /** @return array{commission_amount:string,author_amount:string} */
    public function split(string $gross, string $rate): array
    {
        $base = BigDecimal::of(Money::decimal($gross));
        $percentage = BigDecimal::of($rate)->toScale(2, RoundingMode::Unnecessary);
        if ($base->isNegative() || $percentage->isNegative() || $percentage->isGreaterThan(100)) {
            throw new \InvalidArgumentException('Invalid commission economics.');
        }
        $commission = $base->multipliedBy($percentage)->dividedBy(100, 2, RoundingMode::HalfUp);

        return ['commission_amount' => (string) $commission, 'author_amount' => (string) $base->minus($commission)->toScale(2, RoundingMode::Unnecessary)];
    }
}
