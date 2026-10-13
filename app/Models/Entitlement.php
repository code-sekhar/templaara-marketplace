<?php

namespace App\Models;

use App\Enums\EntitlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int $order_item_id
 * @property int $product_id
 * @property int $product_release_id
 * @property EntitlementStatus $status
 */
class Entitlement extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['license_secret', 'license_key_hash'];

    /** @return HasMany<LicenseActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class)->orderByDesc('id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => EntitlementStatus::class, 'granted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Purchase access history cannot be deleted.'));
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status', 'revoked_at', 'expires_at', 'updated_at'])) {
                throw new \LogicException('Purchase access identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<ProductArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ProductArtifact::class, 'product_artifact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasOne<License, $this> */
    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }

    /** @return HasMany<DownloadAccess, $this> */
    public function accesses(): HasMany
    {
        return $this->hasMany(DownloadAccess::class);
    }
}
