<?php

namespace App\Http\Requests\Products;

class RevisionRequest extends ProductRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('revision')) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['is_featured'] = ['prohibited'];
        $rules['features.*.id'] = ['prohibited'];
        $rules['offers.*.id'] = ['prohibited'];

        return $rules;
    }
}
