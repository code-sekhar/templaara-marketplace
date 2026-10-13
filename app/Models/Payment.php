<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $order_id
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string $idempotency_key
 * @property string $provider
 * @property string $provider_payment_id
 * @property string $provider_order_id
 */
class Payment extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'amount' => 'decimal:2', 'paid_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasOne<PaymentCheckout,$this> */
    public function checkout(): HasOne
    {
        return $this->hasOne(PaymentCheckout::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Commercial history cannot be deleted.'));
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status', 'failure_code', 'failure_message', 'paid_at', 'updated_at'])) {
                throw new \LogicException('Commercial snapshots are immutable.');
            }
        });
    }
}
