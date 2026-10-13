<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class ProductReviewEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Review history is retained.'));
        static::updating(fn () => throw new \LogicException('Review audit is immutable.'));
    }

    /** @return BelongsTo<ProductReview,$this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'review_id');
    }
}
