<?php

namespace App\Services\Products\Workflow;

use App\Enums\LicenseType;
use App\Enums\ProductCurrency;
use App\Models\Product;
use App\Services\Products\Files\SubmissionFiles;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmissionReadiness
{
    /** @return array<string, mixed> */
    public function validate(Product $product, bool $requireFiles = true, bool $loadRelations = true): array
    {
        if ($loadRelations) {
            $product->load(['category:id,name,is_active', 'owner:id,name', 'industries:id,name,is_active', 'technologies:id,name,is_active', 'features', 'offers']);
        }
        $snapshot = $product->attributesToArray();
        unset($snapshot['created_at'], $snapshot['updated_at']);
        $snapshot['category'] = $product->category->toArray();
        $snapshot['owner'] = $product->owner?->toArray();
        $snapshot['industries'] = $product->industries->map(fn ($record) => $record->only(['id', 'name', 'is_active']))->all();
        $snapshot['technologies'] = $product->technologies->map(fn ($record) => $record->only(['id', 'name', 'is_active']))->all();
        $snapshot['features'] = $product->features->map(fn ($record) => $record->only(['id', 'title', 'description', 'sort_order']))->all();
        $snapshot['offers'] = $product->offers->map(fn ($record) => $record->only(['id', 'name', 'description', 'price', 'license_type', 'support_period_days', 'updates_period_days', 'is_default', 'is_active', 'sort_order', 'activation_limit']))->all();
        $validator = Validator::make($snapshot, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:200'],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('is_active', true)],
            'is_active' => ['accepted'],
            'short_description' => ['required', 'string', 'min:30', 'max:500'],
            'description' => ['required', 'string', 'min:50', 'max:30000'],
            'business_problem' => ['required', 'string', 'min:20', 'max:10000'],
            'target_audience' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'target_audience.*' => ['required', 'string', 'min:3', 'max:500'],
            'key_benefits' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'key_benefits.*' => ['required', 'string', 'min:5', 'max:500'],
            'features' => ['required', 'array', 'min:1', 'max:50'],
            'features.*.title' => ['required', 'string', 'min:3', 'max:255'],
            'industries' => ['required', 'array', 'min:1'],
            'industries.*.id' => [Rule::exists('industries', 'id')->where('is_active', true)],
            'technologies' => ['required', 'array', 'min:1'],
            'technologies.*.id' => [Rule::exists('technologies', 'id')->where('is_active', true)],
            'offers' => ['required', 'array', 'min:1', 'max:20'],
            'offers.*.name' => ['required', 'string', 'max:255'],
            'offers.*.activation_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'offers.*.price' => ['required', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'min:0', 'max:9999999999.99'],
            'offers.*.license_type' => [Rule::enum(LicenseType::class)],
            'offers.*.support_period_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'offers.*.updates_period_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'currency' => [Rule::enum(ProductCurrency::class)],
            'demo_url' => ['required', 'url:http,https', 'max:2048'],
            'documentation_url' => ['nullable', 'url:http,https', 'max:2048'],
        ], [
            'category_id.exists' => 'Choose an active primary category before submitting.',
            'is_active.accepted' => 'Activate the product before submitting.',
            'description.required' => 'Add a full product description before submitting.',
            'description.min' => 'Describe your product in at least 50 characters.',
            'short_description.min' => 'Explain the product in at least 30 characters.',
            'business_problem.required' => 'Explain the business problem this software solves.',
            'target_audience.required' => 'Add at least one target audience before submitting.',
            'key_benefits.required' => 'Add at least one key benefit before submitting.',
            'features.required' => 'Add at least one product feature before submitting.',
            'industries.required' => 'Add at least one target industry before submitting.',
            'industries.*.id.exists' => 'Replace inactive target industries before submitting.',
            'technologies.required' => 'Add at least one active technology before submitting.',
            'technologies.*.id.exists' => 'Replace inactive technologies before submitting.',
            'demo_url.required' => 'Provide an HTTP/HTTPS live demo URL before submitting.',
        ]);
        $validator->validate();
        $active = $product->offers->where('is_active', true);
        if ($active->isEmpty() || $product->offers->where('is_default', true)->count() !== 1 || $active->where('is_default', true)->count() !== 1) {
            throw ValidationException::withMessages(['offers' => 'Configure at least one active offer and exactly one active default offer.']);
        }

        if ($requireFiles) {
            $snapshot = [...$snapshot, ...app(SubmissionFiles::class)->readiness($product)];
        }

        return $snapshot;
    }
}
