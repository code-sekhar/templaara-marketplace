<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorEarningLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AuthorEarning,$this> */
    public function earning(): BelongsTo
    {
        return $this->belongsTo(AuthorEarning::class, 'author_earning_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Ledger is append-only.'));
        static::deleting(fn () => throw new \LogicException('Ledger is append-only.'));
    }
}
