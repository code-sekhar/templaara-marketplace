<?php

namespace App\Models;

use App\Enums\ProductMediaType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $disk
 * @property string $path
 * @property bool $is_current
 */
class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $hidden = ['disk', 'path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => ProductMediaType::class, 'is_current' => 'boolean', 'size_bytes' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $file): void {
            if ($file->isDirty(['disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'checksum', 'width', 'height', 'product_id', 'product_release_id', 'type'])) {
                throw new \LogicException('File identity is immutable; upload a replacement.');
            }
        });
    }
}
