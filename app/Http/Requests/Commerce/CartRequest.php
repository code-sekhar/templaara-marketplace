<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['product_slug' => ['required', 'string', 'max:200'], 'offer_id' => ['required', 'integer', 'min:1'],
            ...array_fill_keys(['price', 'currency', 'quantity', 'user_id', 'owner_id', 'status', 'license_type', 'support_period_days', 'updates_period_days'], ['prohibited'])];
    }
}
