<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
class SupportMessage extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return HasMany<SupportAttachment,$this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}
