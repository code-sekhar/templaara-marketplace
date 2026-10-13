<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class ProductReviewResponse extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'edited_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Review history is retained.'));
        static::updating(function (self $record): void {
            $allowed = ['body', 'edited_at', 'review_sequence', 'updated_at'];
            if (array_diff(array_keys($record->getDirty()), $allowed)) {
                throw new \LogicException('Review identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<ProductReview,$this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'review_id');
    }

    /** @return BelongsTo<User,$this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
