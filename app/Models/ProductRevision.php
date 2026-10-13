<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property int $base_release_id
 * @property int $proposed_release_id
 * @property ProductStatus $status
 * @property array<string,mixed> $content
 * @property-read Product $product
 * @property-read ProductRelease $release
 */
class ProductRevision extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => ProductStatus::class, 'content' => 'array'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ProductRelease::class, 'proposed_release_id');
    }

    /** @return BelongsToMany<ProductMedia, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(ProductMedia::class, 'product_revision_media');
    }

    /** @return HasMany<ProductSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(ProductSubmission::class)->orderByDesc('round_number');
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $key): array
    {
        $rows = $this->content[$key] ?? [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \LogicException('Invalid revision rows.');
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new \LogicException('Invalid revision row.');
            }
        }

        return $rows;
    }

    public function editable(): bool
    {
        return in_array($this->status, [ProductStatus::Draft, ProductStatus::ChangesRequested], true);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Revision history cannot be deleted.'));
        static::updating(function (self $r) {
            if ($r->isDirty(['product_id', 'base_release_id', 'proposed_release_id', 'created_by']) || (! in_array($r->getRawOriginal('status'), ['draft', 'changes_requested']) && $r->isDirty('content'))) {
                throw new \LogicException('Frozen revision identity/content.');
            }
        });
    }
}
