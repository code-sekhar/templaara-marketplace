<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPolicy extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'hold_days' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean', 'effective_from' => 'immutable_datetime', 'effective_until' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Policy history is retained.'));
        static::updating(function (self $p) {
            if ($p->isDirty(['name', 'percentage', 'hold_days', 'effective_from', 'created_by']) && OrderItem::where('commission_policy_id', $p->id)->exists()) {
                throw new \LogicException('Used policy economics are immutable.');
            }
        });
    }
}
