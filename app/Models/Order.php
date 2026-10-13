<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $checkout_key
 * @property string $order_number
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property ProductCurrency $currency
 * @property string $grand_total
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 */
class Order extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['tax_snapshot' => 'array', 'refund_window_days' => 'integer', 'currency' => ProductCurrency::class, 'status' => OrderStatus::class, 'payment_status' => PaymentStatus::class, 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'placed_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Commercial history cannot be deleted.'));
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status', 'payment_status', 'paid_at', 'cancelled_at', 'invoice_number', 'settled_payment_id', 'updated_at'])) {
                throw new \LogicException('Commercial snapshots are immutable.');
            }
        });
    }
}
