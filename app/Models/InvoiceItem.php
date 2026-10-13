<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $grand_total
 * @property array<string,mixed> $tax_snapshot
 */
class InvoiceItem extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['tax_snapshot' => 'array', 'unit_price' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'grand_total' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Invoice line is immutable.'));
        static::deleting(fn () => throw new \LogicException('Invoice line is retained.'));
    }
}
