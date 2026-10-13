<?php

namespace App\Http\Requests\Products\Files;

use Illuminate\Foundation\Http\FormRequest;

class ProductFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (['status', 'owner_id', 'origin', 'disk', 'path', 'checksum', 'created_by', 'product_id', 'product_release_id', 'is_current', 'size_bytes', 'mime_type'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
