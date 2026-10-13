<?php

namespace App\Models;

use App\Enums\ProductReleaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property string $version
 * @property ProductReleaseStatus $status
 */
class ProductRelease extends Model
{
    protected $table = 'product_releases';

    protected static function booted(): void
    {
        static::updating(function (self $release): void {
            if ($release->isDirty(['product_id', 'created_by']) || ($release->getRawOriginal('status') !== 'draft' && $release->isDirty(['version', 'changelog', 'release_notes']))) {
                throw new \LogicException('Frozen release content and identity cannot be overwritten.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ProductReleaseStatus::class, 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProductArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ProductArtifact::class);
    }
}
