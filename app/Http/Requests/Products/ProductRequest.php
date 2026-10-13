<?php

namespace App\Http\Requests\Products;

use App\Enums\LicenseType;
use App\Enums\ProductCurrency;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->product();
        if ($this->routeIs('admin.products.*') && ! $this->user()?->can('manageAll', Product::class)) {
            return false;
        }

        return $product
            ? ($this->user()?->can('update', $product) ?? false)
            : ($this->user()?->can($this->routeIs('admin.products.*') ? 'createOfficial' : 'create', Product::class) ?? false);
    }

    public function product(): ?Product
    {
        return $this->route('product');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->product();
        $category = Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $product?->category_id));
        $industries = $product?->industries()->pluck('industries.id')->all() ?? [];
        $technologies = $product?->technologies()->pluck('technologies.id')->all() ?? [];

        return [
            'owner_id' => ['prohibited'],
            'origin' => ['prohibited'],
            'status' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product)],
            'category_id' => ['required', 'integer', $category],
            'short_description' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:30000'],
            'business_problem' => ['nullable', 'string', 'max:10000'],
            'target_audience' => ['sometimes', 'array', 'list', 'max:20'],
            'target_audience.*' => ['required', 'string', 'max:500'],
            'key_benefits' => ['sometimes', 'array', 'list', 'max:30'],
            'key_benefits.*' => ['required', 'string', 'max:500'],
            'currency' => ['required', Rule::enum(ProductCurrency::class)],
            'demo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'demo_username' => ['nullable', 'string', 'max:255'],
            'demo_password' => ['nullable', 'string', 'max:255'],
            'version_label' => ['nullable', 'string', 'max:100'],
            'preview_video_url' => ['nullable', 'url:http,https', 'max:2048'],
            'documentation_url' => ['nullable', 'url:http,https', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => $this->user()?->can('manageAll', Product::class) ? ['sometimes', 'boolean'] : ['prohibited'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'industry_ids' => ['sometimes', 'array', 'list', 'max:50'],
            'industry_ids.*' => ['integer', 'distinct', Rule::exists('industries', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $industries))],
            'technology_ids' => ['sometimes', 'array', 'list', 'max:50'],
            'technology_ids.*' => ['integer', 'distinct', Rule::exists('technologies', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $technologies))],
            'features' => ['sometimes', 'array', 'list', 'max:50'],
            'features.*' => ['array:id,title,description,sort_order'],
            'features.*.id' => $product ? ['nullable', 'integer', 'distinct', Rule::exists('product_features', 'id')->where('product_id', $product->id)] : ['prohibited'],
            'features.*.title' => ['required', 'string', 'max:255'],
            'features.*.description' => ['nullable', 'string', 'max:5000'],
            'features.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'offers' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'offers.*' => ['array:id,name,description,price,license_type,support_period_days,updates_period_days,is_default,is_active,sort_order,activation_limit'],
            'offers.*.id' => $product ? ['nullable', 'integer', 'distinct', Rule::exists('product_offers', 'id')->where('product_id', $product->id)] : ['prohibited'],
            'offers.*.name' => ['required', 'string', 'max:255'],
            'offers.*.description' => ['nullable', 'string', 'max:5000'],
            'offers.*.activation_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'offers.*.price' => ['required', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'min:0', 'max:9999999999.99'],
            'offers.*.license_type' => ['required', Rule::enum(LicenseType::class)],
            'offers.*.support_period_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'offers.*.updates_period_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'offers.*.is_default' => ['required', 'boolean'],
            'offers.*.is_active' => ['required', 'boolean'],
            'offers.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $offers = $this->input('offers', []);
            $defaults = array_filter($offers, fn (array $offer) => (bool) $offer['is_default']);
            if (count($defaults) !== 1 || ! (bool) reset($defaults)['is_active']) {
                $validator->errors()->add('offers', 'Choose exactly one active default offer.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category_id' => 'primary category',
            'industry_ids.*' => 'selected industry',
            'technology_ids.*' => 'selected technology',
            'target_audience.*' => 'target business',
            'key_benefits.*' => 'key benefit',
            'features.*.id' => 'existing feature',
            'features.*.title' => 'feature title',
            'features.*.description' => 'feature description',
            'features.*.sort_order' => 'feature sort order',
            'offers.*.id' => 'existing offer',
            'offers.*.name' => 'offer name',
            'offers.*.activation_limit' => 'activation limit',
            'offers.*.price' => 'offer price',
            'offers.*.license_type' => 'offer license type',
            'offers.*.support_period_days' => 'support period',
            'offers.*.updates_period_days' => 'updates period',
            'offers.*.sort_order' => 'offer sort order',
        ];
    }
}
