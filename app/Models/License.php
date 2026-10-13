<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $order_item_id
 * @property int $product_id
 * @property int $product_release_id
 * @property LicenseStatus $status
 */
class License extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['license_secret', 'license_key_hash'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => LicenseStatus::class, 'issued_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'license_secret' => 'encrypted'];
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

    /** @return BelongsTo<Entitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class, 'entitlement_id');
    }
}
