<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundCompensation extends Model
{
    protected $table = 'refund_compensations';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'author_amount' => 'decimal:2', 'paid_exposure' => 'decimal:2', 'created_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Refund financial history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Refund financial history is retained.'));
    }
}
