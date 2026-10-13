<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CatalogRequest extends FormRequest
{
    abstract protected function catalog(): string;

    abstract protected function record(): ?Model;

    public function authorize(): bool
    {
        return $this->user()?->can($this->catalog().($this->record() ? '.update' : '.create')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($this->catalog(), 'slug')->ignore($this->record())],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
