<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use Illuminate\Validation\Rule;

class CategoryRequest extends CatalogRequest
{
    protected function catalog(): string
    {
        return 'categories';
    }

    protected function record(): ?Category
    {
        return $this->route('category');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id'), Rule::notIn([$this->record()?->getKey()])],
            'icon' => ['nullable', 'string', 'max:255'],
        ];
    }
}
