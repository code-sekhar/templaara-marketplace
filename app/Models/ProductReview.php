<?php

namespace App\Models;

use App\Enums\ProductReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $customer_user_id
 * @property int $product_id
 * @property int $verified_order_item_id
 * @property ProductReviewStatus $status
 */
class ProductReview extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['status' => ProductReviewStatus::class, 'rating' => 'integer', 'is_verified' => 'boolean', 'published_at' => 'immutable_datetime', 'edited_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'hidden_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Review history is retained.'));
        static::updating(function (self $record): void {
            $allowed = ['rating', 'title', 'body', 'status', 'is_verified', 'verified_order_item_id', 'edited_at', 'withdrawn_at', 'hidden_at', 'event_sequence', 'updated_at'];
            if (array_diff(array_keys($record->getDirty()), $allowed)) {
                throw new \LogicException('Review identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<Product,$this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User,$this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<OrderItem,$this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'verified_order_item_id');
    }

    /** @return HasOne<ProductReviewResponse,$this> */
    public function response(): HasOne
    {
        return $this->hasOne(ProductReviewResponse::class, 'review_id');
    }

    /** @return HasMany<ProductReviewEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProductReviewEvent::class, 'review_id')->orderBy('sequence');
    }
}
