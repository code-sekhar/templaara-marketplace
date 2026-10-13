<?php

namespace App\Services\Settings;

use App\Services\Commerce\Money;
use App\Services\Invoicing\TaxIdentity;
use App\Support\Countries;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SettingsRegistry
{
    public const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP'];

    public const SUPPORT_TYPES = ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'txt', 'log'];

    public const PRODUCT_CEILINGS = ['cover_max_kb' => 20480, 'screenshot_max_kb' => 20480, 'screenshots_max' => 30, 'package_max_kb' => 524288, 'documentation_max_kb' => 51200];

    /** @return array<string,array<string,array<string,mixed>>> */
    public function definitions(): array
    {
        $string = ['required', 'string', 'max:150', 'not_regex:/[<>\x00-\x1f\x7f]/'];
        $d = [];
        foreach (['marketplace_name' => ['Templaara', 'app.name'], 'marketplace_tagline' => ['Software built for your business.', null], 'support_display_name' => ['Templaara Support', null], 'support_contact_email' => [null, null], 'business_contact_email' => [null, null]] as $k => [$default,$config]) {
            $d['general'][$k] = $this->define(str_ends_with($k, 'email') ? 'email' : 'string', $default, $config, str_ends_with($k, 'email') ? ['nullable', 'email:rfc', 'max:254'] : $string, 'Public display/contact information.');
        }
        $d['commerce']['enabled_currencies'] = $this->define('list', self::CURRENCIES, null, ['required', 'array', 'list', 'min:1', 'max:4'], 'Enabled for new commercial configuration, cart additions and checkout only.');
        $d['commerce']['maximum_cart_items'] = $this->define('integer', 50, 'commerce.cart_limit', ['required', 'integer', 'between:1,100'], 'Existing carts remain viewable; resolve excess items before adding or checking out.');
        $d['refunds']['refund_window_days'] = $this->define('integer', null, 'refunds.window_days', ['required', 'integer', 'between:0,3650'], 'New Orders snapshot this policy; legacy Orders retain request-time policy.', true);
        $d['authors']['commission_percentage'] = $this->define('decimal', '20.00', null, ['required', 'string', 'regex:/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', 'numeric', 'between:0,100'], 'Creates a new effective-dated CommissionPolicy. Applies to future purchases only.', true);
        $d['authors']['earning_hold_days'] = $this->define('integer', 7, null, ['required', 'integer', 'between:0,3650'], 'Future purchase commission-policy snapshots only.', true);
        foreach (self::CURRENCIES as $c) {
            $d['payouts']['minimum_'.$c] = $this->define('decimal', null, 'payouts.minimums.'.$c, ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'numeric', 'gt:0'], 'Minimum '.$c.' for new requests only; existing requests and historical currencies remain operable.');
        }
        foreach (self::PRODUCT_CEILINGS as $k => $ceiling) {
            $d['products'][$k] = $this->define('integer', match ($k) {
                'cover_max_kb' => 5120,'screenshot_max_kb' => 8192,'screenshots_max' => 12,'package_max_kb' => 256000,default => 25600
            }, 'product_files.'.$k, ['required', 'integer', 'between:'.($k === 'screenshots_max' ? 2 : 1).','.$ceiling], 'New uploads only. Hard ceiling '.$ceiling.($k === 'screenshots_max' ? ' files.' : ' KiB.'));
        }
        $d['support']['attachment_max_kb'] = $this->define('integer', 10240, 'support.attachment_max_kb', ['required', 'integer', 'between:1,10240'], 'New attachments only; hard ceiling 10 MiB.');
        $d['support']['attachments_max'] = $this->define('integer', 5, 'support.attachments_max', ['required', 'integer', 'between:1,5'], 'New message uploads only; hard ceiling five files.');
        $d['support']['allowed_types'] = $this->define('list', self::SUPPORT_TYPES, null, ['required', 'array', 'list', 'min:1', 'max:7'], 'Subset of the fixed safe extension/content allow-list.');
        $d['notifications']['sender_name'] = $this->define('string', 'Templaara', 'mail.from.name', $string, 'Display name for future notification mail. Transport/address remain infrastructure configuration.');
        $d['notifications']['support_sender_name'] = $this->define('string', 'Templaara Support', null, $string, 'Display name for future support notification mail.');
        $d['notifications']['show_support_contact'] = $this->define('boolean', false, null, ['required', 'boolean'], 'Include the public support contact in future notification emails.');
        $d['reporting']['timezone'] = $this->define('timezone', 'Asia/Kolkata', 'analytics.timezone', ['required', 'string', 'timezone:all'], 'Reporting boundaries only. Does not change database timezone or stored instants.');

        foreach (['enabled' => false, 'prices_include_tax' => false, 'policy_reviewed' => false] as $key => $default) {
            $d['invoicing'][$key] = $this->define('boolean', $default, null, ['required', 'boolean'], 'Future purchases only; legal review remains required.', true);
        }
        foreach (['legal_name', 'trading_name', 'address_line_1', 'address_line_2', 'city', 'state_region', 'postal_code', 'tax_registration_number', 'customer_footer'] as $key) {
            $d['invoicing'][$key] = $this->define('string', '', null, ['nullable', 'string', 'max:500', 'not_regex:/[<>\x00-\x1f\x7f]/'], 'Issuer identity for future document snapshots.', true);
        }
        $d['invoicing']['contact_email'] = $this->define('email', null, null, ['nullable', 'email:rfc', 'max:254'], 'Public issuer contact, never transport credentials.', true);
        $d['invoicing']['country_code'] = $this->define('string', 'IN', null, ['required', Rule::in(Countries::codes())], 'ISO issuer country.', true);
        $d['invoicing']['issuer_mode'] = $this->define('string', 'platform', null, ['required', Rule::in(['platform'])], 'Platform scope only; author issuer mode not operational.', true);
        foreach (['invoice_prefix' => 'INV', 'credit_prefix' => 'CN'] as $key => $default) {
            $d['invoicing'][$key] = $this->define('string', $default, null, ['required', 'string', 'regex:/^[A-Z][A-Z0-9-]{0,5}$/D'], 'Up to six uppercase characters; server allocates immutable numbers.', true);
        }
        $d['invoicing']['tax_mode'] = $this->define('string', 'none', null, ['required', Rule::in(['none', 'gst', 'international'])], 'No implicit rate or global tax determination.', true);
        $d['invoicing']['tax_rate'] = $this->define('decimal', null, null, ['nullable', 'string', 'regex:/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/D'], 'Explicit reviewed 0–100 percentage; no default rate.', true);
        $d['invoicing']['international_type'] = $this->define('string', 'VAT', null, ['required', Rule::in(['VAT', 'SALES_TAX', 'GST', 'OTHER'])], 'Descriptive configured classification only.', true);
        $d['invoicing']['international_countries'] = $this->define('list', [], null, ['array', 'list', 'max:249'], 'Explicit countries covered by reviewed fixed policy; no nexus engine.', true);
        $d['invoicing']['gstin'] = $this->define('string', '', null, ['nullable', 'string', 'regex:'.TaxIdentity::GSTIN], 'Structural format only; no external validation.', true);
        $d['invoicing']['gst_state_code'] = $this->define('string', '', null, ['nullable', Rule::in(TaxIdentity::stateCodes())], 'Explicit issuer state code.', true);
        $d['invoicing']['service_code'] = $this->define('string', '', null, ['nullable', 'string', 'regex:/^\d{4,8}$/D'], 'Optional professionally reviewed HSN/SAC, never inferred.', true);

        return $d;
    }

    /**
     * @param  array<int,mixed>  $rules
     * @return array<string,mixed>
     */
    private function define(string $type, mixed $default, ?string $config, array $rules, string $help, bool $snapshot = false): array
    {
        return ['type' => $type, 'default' => $default, 'config' => $config, 'rules' => $rules, 'description' => $help, 'editable' => true, 'sensitive' => false, 'snapshot' => $snapshot, 'reload' => false];
    }

    /** @return array<string,array<string,mixed>> */
    public function group(string $group): array
    {
        return $this->definitions()[$group] ?? throw ValidationException::withMessages(['group' => 'Unknown settings section.']);
    }

    public function canonical(string $group, string $key, mixed $value): mixed
    {
        $def = $this->group($group)[$key] ?? throw ValidationException::withMessages(['values.'.$key => 'Unsupported setting. Secrets cannot be stored.']);
        $rules = ['value' => $def['rules']];
        if ($def['type'] === 'list') {
            $rules['value.*'] = ['required', 'string', 'distinct', Rule::in($key === 'enabled_currencies' ? self::CURRENCIES : ($key === 'international_countries' ? Countries::codes() : self::SUPPORT_TYPES))];
        }
        $valid = Validator::make(['value' => $value], $rules);
        if ($valid->fails()) {
            throw ValidationException::withMessages(['values.'.$key => $valid->errors()->first()]);
        }
        if ($def['type'] === 'email' && $value === null) {
            return '';
        }
        if ($def['type'] === 'boolean') {
            return (bool) $value;
        }
        if ($def['type'] === 'integer') {
            return (int) $value;
        }
        if ($value === null) {
            return in_array($def['type'], ['string', 'email'], true) ? '' : null;
        }
        if ($def['type'] === 'decimal') {
            return Money::decimal($value);
        }
        if ($def['type'] === 'list') {
            sort($value, SORT_STRING);

            return $value;
        }

        return $value;
    }
}
