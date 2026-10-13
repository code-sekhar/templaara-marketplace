<?php

namespace App\Http\Requests\Commerce;

use App\Services\Invoicing\TaxIdentity;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper($this->input('country_code'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $countries = Countries::codes();

        return [
            'tax_quote_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'idempotency_key' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'billing_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'address_line_1' => ['required', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'], 'state_region' => ['required', 'string', 'max:100'], 'postal_code' => ['required', 'string', 'max:30'],
            'customer_tax_id' => ['nullable', 'string', 'max:80', 'not_regex:/[<>\\x00-\\x1f]/'],
            'customer_gstin' => ['nullable', 'string', 'regex:'.TaxIdentity::GSTIN],
            'gst_state_code' => ['nullable', Rule::in(TaxIdentity::stateCodes())],
            'country_code' => ['required', Rule::in($countries)],
            ...array_fill_keys(['tax_snapshot', 'issuer_snapshot', 'policy_snapshot', 'invoice_number', 'price', 'currency', 'user_id', 'status', 'payment_status', 'subtotal', 'grand_total', 'tax_total', 'discount_total', 'items', 'card_number', 'cvv'], ['prohibited']),
        ];
    }
}
