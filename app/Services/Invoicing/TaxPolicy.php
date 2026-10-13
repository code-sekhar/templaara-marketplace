<?php

namespace App\Services\Invoicing;

use App\Services\Settings\MarketplaceSettings;
use Illuminate\Validation\ValidationException;

final class TaxPolicy
{
    /** @return array<string,mixed> */
    public function current(): array
    {
        $section = app(MarketplaceSettings::class)->section('invoicing', true);
        $policy = array_map(fn ($row) => $row['value'], $section);
        $policy['versions'] = array_map(fn ($row) => $row['version'], $section);
        $policy['identifier'] = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        if ($policy['enabled']) {
            $this->validate($policy);
        } elseif ($policy['tax_mode'] !== 'none') {
            throw ValidationException::withMessages(['tax' => 'Tax collection requires configured invoicing.']);
        }

        return $policy;
    }

    /** @param array<string,mixed> $policy */
    public function validate(array $policy): void
    {
        if ($policy['invoice_prefix'] === $policy['credit_prefix']) {
            throw ValidationException::withMessages(['tax' => 'Invoice and credit note prefixes must differ.']);
        }
        foreach (['legal_name', 'address_line_1', 'city', 'state_region', 'postal_code', 'contact_email'] as $field) {
            if (empty($policy[$field])) {
                throw ValidationException::withMessages(['tax' => 'Invoice issuer configuration is incomplete.']);
            }
        }
        if ($policy['tax_mode'] !== 'none' && (! $policy['policy_reviewed'] || $policy['tax_rate'] === null)) {
            throw ValidationException::withMessages(['tax' => 'An explicit reviewed tax rate/policy is required.']);
        }
        if ($policy['tax_mode'] === 'gst' && ($policy['country_code'] !== 'IN' || empty($policy['gstin']) || empty($policy['gst_state_code']) || substr($policy['gstin'], 0, 2) !== $policy['gst_state_code'])) {
            throw ValidationException::withMessages(['tax' => 'Configure a coherent Indian issuer GSTIN/state before GST collection.']);
        }
        if ($policy['tax_mode'] === 'international' && empty($policy['international_countries'])) {
            throw ValidationException::withMessages(['tax' => 'Specify countries covered by the reviewed international policy.']);
        }
    }
}
