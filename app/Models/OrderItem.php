<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $product_id
 * @property int $product_offer_id
 * @property int $product_release_id
 * @property int $source_cart_item_id
 * @property string $cart_terms_hash
 * @property string $unit_price
 * @property string $line_total
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['tax_snapshot' => 'array', 'commission_rate' => 'decimal:2', 'commissionable_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'author_amount' => 'decimal:2', 'earning_hold_days' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasOne<AuthorEarning,$this> */
    public function earning(): HasOne
    {
        return $this->hasOne(AuthorEarning::class);
    }

    /** @return HasOne<Entitlement, $this> */
    public function entitlement(): HasOne
    {
        return $this->hasOne(Entitlement::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Commercial history cannot be deleted.'));
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), [])) {
                throw new \LogicException('Commercial snapshots are immutable.');
            }
        });
    }
}
