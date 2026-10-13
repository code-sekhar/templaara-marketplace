<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operational state only; Payment retains immutable financial truth.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $provider
 * @property string $state
 * @property string|null $provider_order_id
 * @property string|null $provider_payment_id
 * @property string|null $checkout_url
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable $created_at
 * @property int $attempts
 */
class PaymentCheckout extends Model
{
    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['claimed_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<Payment,$this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Payment operations history is retained.'));
        static::updating(function (self $record): void {
            if ($record->isDirty(['payment_id', 'provider']) || ($record->getOriginal('provider_order_id') !== null && $record->isDirty('provider_order_id'))
                || ($record->getOriginal('provider_payment_id') !== null && $record->isDirty('provider_payment_id'))) {
                throw new \LogicException('Bound provider identities are immutable.');
            }
        });
    }
}
