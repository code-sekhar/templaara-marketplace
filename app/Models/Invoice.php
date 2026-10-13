<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_uuid
 * @property string $number
 * @property string $kind
 * @property string $currency
 * @property string $grand_total
 * @property string $payment_display
 * @property string $provenance
 * @property array<string,mixed> $customer_snapshot
 * @property array<string,mixed> $issuer_snapshot
 * @property array<string,mixed> $policy_snapshot
 * @property CarbonImmutable $issued_at
 * @property Order $order
 * @property Invoice|null $original
 * @property Refund|null $refund
 */
class Invoice extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'issuer_snapshot' => 'array', 'policy_snapshot' => 'array', 'issued_at' => 'immutable_datetime',
            'subtotal' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2'];
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Issued invoice is immutable.'));
        static::deleting(fn () => throw new \LogicException('Issued invoice is retained.'));
    }
}
