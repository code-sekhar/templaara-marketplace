<?php

namespace App\Models;

use App\Enums\ProductCurrency;
use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property string|null $preview_video_url
 * @property int $id
 * @property int|null $owner_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property ProductOrigin $origin
 * @property ProductStatus $status
 * @property ProductCurrency $currency
 * @property bool $is_featured
 * @property bool $is_active
 * @property array<int, string> $target_audience
 * @property array<int, string> $key_benefits
 */
#[Fillable(['category_id', 'name', 'slug', 'short_description', 'description', 'business_problem', 'target_audience', 'key_benefits', 'currency', 'demo_url', 'demo_username', 'demo_password', 'version_label', 'documentation_url', 'preview_video_url', 'is_active', 'meta_title', 'meta_description'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            $product->forceFill(['public_uuid' => (string) Str::uuid()]);
        });
        static::updating(function (self $product): void {
            if ($product->isDirty('public_uuid')) {
                throw new \LogicException('Public product identity is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origin' => ProductOrigin::class,
            'status' => ProductStatus::class,
            'currency' => ProductCurrency::class,
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'target_audience' => 'array',
            'key_benefits' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Industry, $this> */
    public function industries(): BelongsToMany
    {
        return $this->belongsToMany(Industry::class)->withTimestamps();
    }

    /** @return BelongsToMany<Technology, $this> */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class)->withTimestamps();
    }

    /** @return HasMany<ProductFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(ProductFeature::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ProductOffer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ProductReview, $this> */
    public function customerReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** @return HasMany<ProductSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(ProductSubmission::class)->orderByDesc('round_number');
    }

    /** @return HasMany<ProductLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ProductLifecycleEvent::class)->orderByDesc('id');
    }

    /** @return HasMany<ProductMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    /** @return HasMany<ProductRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(ProductRelease::class)->orderByDesc('id');
    }

    /** @return HasOne<ProductMedia, $this> */
    public function cover(): HasOne
    {
        return $this->hasOne(ProductMedia::class)->where('type', 'cover')->where('is_current', true);
    }

    /** @return HasMany<ProductMedia, $this> */
    public function screenshots(): HasMany
    {
        return $this->media()->where('type', 'screenshot')->where('is_current', true)->orderBy('sort_order')->orderBy('id');
    }
}
