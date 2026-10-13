<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 */
class ProductLifecycleEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['action' => ProductWorkflowAction::class, 'from_status' => ProductStatus::class, 'to_status' => ProductStatus::class, 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Moderation history is immutable.'));
        static::deleting(fn () => throw new LogicException('Moderation history is immutable.'));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<ProductSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(ProductSubmission::class, 'product_submission_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
