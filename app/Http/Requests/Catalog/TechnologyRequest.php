<?php

namespace App\Http\Requests\Catalog;

use App\Models\Technology;

class TechnologyRequest extends CatalogRequest
{
    protected function catalog(): string
    {
        return 'technologies';
    }

    protected function record(): ?Technology
    {
        return $this->route('technology');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'logo' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
