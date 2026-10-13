<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class PayoutAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AuthorEarning,$this> */
    public function earning(): BelongsTo
    {
        return $this->belongsTo(AuthorEarning::class, 'author_earning_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Allocation is immutable.'));
        static::deleting(fn () => throw new \LogicException('Allocation is retained.'));
    }
}
