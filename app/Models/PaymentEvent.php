<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $payment_id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $payload_hash
 */
class PaymentEvent extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
