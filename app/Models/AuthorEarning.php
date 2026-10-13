<?php

namespace App\Models;

use App\Enums\AuthorEarningStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $author_user_id
 * @property int $order_id
 * @property AuthorEarningStatus $status
 */
class AuthorEarning extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => AuthorEarningStatus::class, 'gross_amount' => 'decimal:2', 'commission_rate' => 'decimal:2', 'commission_amount' => 'decimal:2', 'author_amount' => 'decimal:2', 'hold_days' => 'integer', 'available_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OrderItem,$this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<Order,$this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User,$this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return HasMany<AuthorEarningLedgerEntry,$this> */
    public function ledger(): HasMany
    {
        return $this->hasMany(AuthorEarningLedgerEntry::class);
    }

    /** @return BelongsTo<Product,$this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductRelease,$this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ProductRelease::class, 'product_release_id');
    }

    /** @return BelongsTo<CommissionPolicy,$this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CommissionPolicy::class, 'commission_policy_id');
    }

    /** @return BelongsTo<AuthorProfile,$this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AuthorProfile::class, 'author_profile_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Financial history is retained.'));
        static::updating(function (self $e) {
            if (array_diff(array_keys($e->getDirty()), ['status', 'released_at', 'updated_at']) || $e->getRawOriginal('status') !== 'pending' || $e->status !== AuthorEarningStatus::Available) {
                throw new \LogicException('Financial facts are immutable.');
            }
        });
    }
}
