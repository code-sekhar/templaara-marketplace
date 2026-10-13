<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $order_id
 * @property int $customer_user_id
 * @property RefundStatus $status
 */
class Refund extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'amount' => 'decimal:2', 'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return HasMany<RefundItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /** @return HasMany<RefundEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class);
    }

    /** @return BelongsTo<Order,$this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Refund history is retained.'));
        static::updating(function (self $r) {
            if (array_diff(array_keys($r->getDirty()), ['status', 'reviewed_at', 'reviewed_by', 'processing_at', 'processing_by', 'completed_at', 'completed_by', 'failed_at', 'failed_by', 'cancelled_at', 'cancelled_by', 'failure_reason', 'cancellation_reason', 'admin_note', 'external_reference', 'updated_at']) || in_array($r->getRawOriginal('status'), ['completed', 'failed', 'cancelled'], true)) {
                throw new \LogicException('Refund financial snapshot is immutable.');
            }
        });
    }
}
