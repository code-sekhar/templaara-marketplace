<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class SupportAttachment extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

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

    /** @return BelongsTo<SupportMessage,$this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }

    /** @return BelongsTo<User,$this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}
