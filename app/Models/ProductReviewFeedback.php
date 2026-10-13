<?php

namespace App\Models;

use App\Enums\ProductFeedbackSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductReviewFeedback extends Model
{
    protected $table = 'product_review_feedback';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['section' => ProductFeedbackSection::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Moderation history is immutable.'));
        static::deleting(fn () => throw new LogicException('Moderation history is immutable.'));
    }

    /** @return BelongsTo<ProductSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(ProductSubmission::class, 'product_submission_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
