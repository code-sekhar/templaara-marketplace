<?php

namespace App\Http\Requests\Catalog;

use App\Models\Industry;

class IndustryRequest extends CatalogRequest
{
    protected function catalog(): string
    {
        return 'industries';
    }

    protected function record(): ?Industry
    {
        return $this->route('industry');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'icon' => ['nullable', 'string', 'max:255'],
        ];
    }
}
