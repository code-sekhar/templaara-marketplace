<?php

namespace App\Services\Invoicing;

use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class CheckoutTax
{
    /** @param array<string,mixed> $quote
     * @param  array<string,mixed>  $billing
     */
    public function hash(array $quote, array $billing, string $cartKey): string
    {
        return hash('sha256', json_encode([$quote, $cartKey, $billing['country_code'] ?? '', $billing['gst_state_code'] ?? '', $billing['customer_gstin'] ?? ''], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $prices
     * @param  array<string,mixed>  $billing
     * @return array<string,mixed>
     */
    public function quote(array $prices, array $billing): array
    {
        $policy = app(TaxPolicy::class)->current();
        $lines = array_map(fn ($price) => app(TaxCalculator::class)->line($price, $billing, $policy), $prices);
        $total = Money::sum(array_column($lines, 'grand_total'));
        if (BigDecimal::of($total)->isGreaterThan('999999999999.99')) {
            throw ValidationException::withMessages(['tax' => 'Order total exceeds supported money bounds.']);
        }

        return ['policy' => $policy, 'commercial_subtotal' => Money::sum($prices), 'taxable_subtotal' => Money::sum(array_column($lines, 'taxable_amount')), 'tax_total' => Money::sum(array_column($lines, 'tax_amount')), 'grand_total' => $total, 'lines' => $lines];
    }
}
