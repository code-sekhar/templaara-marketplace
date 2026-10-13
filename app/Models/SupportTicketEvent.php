<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class SupportTicketEvent extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Support history is retained.'));
        static::updating(function (self $r): void {
            if (array_diff(array_keys($r->getDirty()), [])) {
                throw new \LogicException('Support history identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<SupportTicket,$this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
