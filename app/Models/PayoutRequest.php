<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $author_user_id
 * @property PayoutStatus $status
 * @property string $currency
 * @property string $amount
 * @property string $payout_number
 */
class PayoutRequest extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['method_snapshot_encrypted'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['status' => PayoutStatus::class, 'amount' => 'decimal:2', 'method_snapshot_encrypted' => 'encrypted:array', 'method_display_snapshot' => 'array', 'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return HasMany<PayoutAllocation,$this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PayoutAllocation::class);
    }

    /** @return HasMany<PayoutEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(PayoutEvent::class);
    }

    /** @return BelongsTo<User,$this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Payout history is retained.'));
        static::updating(function (self $p) {
            if (array_diff(array_keys($p->getDirty()), ['status', 'reviewed_at', 'reviewed_by', 'processing_at', 'processing_by', 'paid_at', 'paid_by', 'failed_at', 'failed_by', 'cancelled_at', 'cancelled_by', 'failure_reason', 'cancellation_reason', 'admin_note', 'external_reference', 'updated_at']) || in_array($p->getRawOriginal('status'), ['paid', 'failed', 'cancelled'], true)) {
                throw new \LogicException('Payout financial history is immutable.');
            }
        });
    }
}
