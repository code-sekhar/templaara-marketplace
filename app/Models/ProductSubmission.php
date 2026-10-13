<?php

namespace App\Models;

use App\Enums\ProductReviewDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $product_id
 * @property int $round_number
 * @property int $submitted_by
 * @property int|null $reviewed_by
 * @property ProductReviewDecision|null $decision
 * @property array<string, mixed> $snapshot
 */
class ProductSubmission extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decision' => ProductReviewDecision::class, 'snapshot' => 'array', 'submitted_at' => 'immutable_datetime', 'review_started_at' => 'immutable_datetime', 'decision_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Submission history cannot be deleted.'));
        static::updating(function (self $submission): void {
            if ($submission->getRawOriginal('decision_at') !== null || $submission->isDirty(['product_id', 'product_revision_id', 'round_number', 'submitted_by', 'submitted_at', 'snapshot', 'snapshot_version'])) {
                throw new LogicException('Submission identity, snapshot and closed rounds are immutable.');
            }
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ProductReviewFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(ProductReviewFeedback::class)->orderBy('id');
    }
}
