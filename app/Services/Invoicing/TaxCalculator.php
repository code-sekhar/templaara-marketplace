<?php

namespace App\Services\Invoicing;

use App\Services\Commerce\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class TaxCalculator
{
    /** Exact strings only; caller supplies authoritative purchased/cart prices, never client totals. */
    /** @param array<string,mixed> $billing
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function line(string $price, array $billing, array $policy): array
    {
        $price = Money::decimal($price);
        if (BigDecimal::of($price)->isNegative()) {
            throw new \InvalidArgumentException('Negative commercial price.');
        }
        $rate = '0.00';
        $type = 'NONE';
        $reason = 'tax_not_configured';
        $jurisdiction = strtoupper((string) ($billing['country_code'] ?? ''));
        if ($policy['tax_mode'] === 'gst' && $jurisdiction === 'IN') {
            $state = $billing['gst_state_code'] ?? null;
            if (! in_array($state, TaxIdentity::stateCodes(), true)) {
                throw ValidationException::withMessages(['gst_state_code' => 'Enter the Indian place-of-supply state code.']);
            }
            if (! empty($billing['customer_gstin']) && substr($billing['customer_gstin'], 0, 2) !== $state) {
                throw ValidationException::withMessages(['customer_gstin' => 'GSTIN state must match the declared place of supply.']);
            }
            $jurisdiction .= '-'.$state;
            $type = $state === $policy['gst_state_code'] ? 'CGST_SGST' : 'IGST';
            $rate = $policy['tax_rate'];
            $reason = 'configured_india_policy';
        } elseif ($policy['tax_mode'] === 'international' && in_array($jurisdiction, $policy['international_countries'], true)) {
            $type = $policy['international_type'];
            $rate = $policy['tax_rate'];
            $reason = 'configured_country_policy';
        } elseif ($policy['tax_mode'] !== 'none') {
            $reason = 'outside_configured_policy';
        }
        $factor = BigDecimal::of($rate)->dividedBy('100', 4, RoundingMode::Unnecessary);
        $inclusive = (bool) $policy['prices_include_tax'];
        $base = $inclusive ? BigDecimal::of($price)->dividedBy(BigDecimal::one()->plus($factor), 2, RoundingMode::HalfUp) : BigDecimal::of($price);
        $tax = $inclusive ? BigDecimal::of($price)->minus($base) : $base->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp);
        $total = $base->plus($tax)->toScale(2);
        if ($total->isGreaterThan('999999999999.99')) {
            throw ValidationException::withMessages(['tax' => 'Taxed amount exceeds supported money bounds.']);
        }
        $components = [];
        if ($type !== 'NONE') {
            if ($type === 'CGST_SGST') {
                $half = $tax->dividedBy(2, 2, RoundingMode::HalfUp);
                $halfRate = (string) BigDecimal::of($rate)->dividedBy(2, 4, RoundingMode::Unnecessary);
                $components = [['type' => 'CGST', 'rate' => $halfRate, 'amount' => (string) $half], ['type' => 'SGST', 'rate' => $halfRate, 'amount' => (string) $tax->minus($half)->toScale(2)]];
            } else {
                $components = [['type' => $type, 'rate' => $rate, 'amount' => (string) $tax]];
            }
        }

        return ['commercial_amount' => $price, 'taxable_amount' => (string) $base->toScale(2), 'tax_amount' => (string) $tax->toScale(2), 'grand_total' => (string) $total,
            'additional_tax' => $inclusive ? '0.00' : (string) $tax, 'inclusive' => $inclusive, 'type' => $type, 'rate' => $rate, 'jurisdiction' => $jurisdiction,
            'components' => $components, 'reason' => $reason, 'policy_identifier' => $policy['identifier'], 'service_code' => $policy['service_code']];
    }
}
