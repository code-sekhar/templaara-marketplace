<?php

namespace App\Services\Products;

use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Settings\MarketplaceSettings;
use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, ProductOrigin $origin): Product
    {
        Gate::forUser($actor)->authorize($origin === ProductOrigin::Templaara ? 'createOfficial' : 'create', Product::class);

        return $this->persist($actor, null, $data, $origin);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Product $product, array $data): Product
    {
        Gate::forUser($actor)->authorize('update', $product);

        return $this->persist($actor, $product->id, $data);
    }

    /** @param array<string, mixed> $data */
    private function persist(User $actor, ?int $id, array $data, ?ProductOrigin $origin = null): Product
    {
        $automatic = empty($data['slug']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $id, $data, $origin, $automatic): Product {
                    $settings = app(MarketplaceSettings::class);
                    $settings->lock();
                    if (isset($data['currency']) && ! in_array($data['currency'], $settings->currencies(), true)) {
                        throw ValidationException::withMessages(['currency' => 'This currency is disabled for new commercial configuration. Existing purchases remain available.']);
                    }
                    $product = $id ? Product::whereKey($id)->lockForUpdate()->firstOrFail() : new Product;
                    if ($id) {
                        Gate::forUser($actor)->authorize('update', $product);
                    } else {
                        $product->forceFill([
                            'owner_id' => $origin === ProductOrigin::Author ? $actor->id : null,
                            'origin' => $origin,
                            'status' => ProductStatus::Draft,
                            'target_audience' => [],
                            'key_benefits' => [],
                        ]);
                    }
                    $fields = Arr::except($data, ['features', 'offers', 'industry_ids', 'technology_ids', 'owner_id', 'origin', 'status', 'is_featured']);
                    if ($automatic) {
                        $fields['slug'] = $id && ! array_key_exists('slug', $data)
                            ? $product->slug
                            : app(UniqueSlug::class)->generate($product, $data['name'], 'product');
                    }
                    $product->fill($fields);
                    if ($actor->can('manageAll', Product::class) && array_key_exists('is_featured', $data)) {
                        $product->forceFill(['is_featured' => $data['is_featured']]);
                    }
                    $product->save();
                    if (array_key_exists('industry_ids', $data)) {
                        $product->industries()->sync($data['industry_ids']);
                    }
                    if (array_key_exists('technology_ids', $data)) {
                        $product->technologies()->sync($data['technology_ids']);
                    }
                    if (array_key_exists('features', $data)) {
                        $this->syncRows($product->features(), $data['features']);
                    }
                    // Clear existing defaults first so PostgreSQL's partial unique index permits switching offers.
                    $product->offers()->update(['is_default' => false]);
                    $this->syncRows($product->offers(), $data['offers']);

                    return $product;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $automatic || $attempt === 4) {
                    throw ValidationException::withMessages(['slug' => 'This slug is already in use. Please choose another.']);
                }
            }
        }

        throw ValidationException::withMessages(['slug' => 'Unable to reserve this slug.']);
    }

    /**
     * @template T of Model
     *
     * @param  HasMany<T, Product>  $relation
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncRows(HasMany $relation, array $rows): void
    {
        $kept = [];
        foreach ($rows as $row) {
            $rowId = $row['id'] ?? null;
            $attributes = Arr::except($row, ['id']);
            if ($rowId) {
                $record = (clone $relation)->whereKey($rowId)->first();
                if (! $record) {
                    throw ValidationException::withMessages(['offers' => 'A feature or offer has changed. Reload the editor and try again.']);
                }
                $record->fill($attributes)->save();
            } else {
                $record = $relation->create($attributes);
            }
            $kept[] = $record->getKey();
        }
        (clone $relation)->whereNotIn('id', $kept)->delete();
    }
}
