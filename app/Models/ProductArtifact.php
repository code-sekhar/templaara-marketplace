<?php

namespace App\Models;

use App\Enums\ProductArtifactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_release_id
 * @property string $disk
 * @property string $path
 * @property bool $is_current
 */
class ProductArtifact extends Model
{
    protected $table = 'product_artifacts';

    protected $hidden = ['disk', 'path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => ProductArtifactType::class, 'is_current' => 'boolean', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<ProductRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ProductRelease::class, 'product_release_id');
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
