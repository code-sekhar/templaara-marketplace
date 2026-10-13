<?php

namespace App\Services\Payouts;

use App\Services\Settings\MarketplaceSettings;
use Illuminate\Validation\ValidationException;

class PayoutMinimums
{
    public function forCurrency(string $currency): string
    {
        return app(MarketplaceSettings::class)->payoutMinimum($currency);
    }

    /** @return array<string,string|null> */
    public function all(): array
    {
        $result = [];
        foreach (['INR', 'USD', 'EUR', 'GBP'] as $c) {
            try {
                $result[$c] = $this->forCurrency($c);
            } catch (ValidationException) {
                $result[$c] = null;
            }
        }

        return $result;
    }
}
