<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorApplicationEvent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Author review history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Author review history is retained.'));
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<AuthorApplication,$this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AuthorApplication::class, 'author_application_id');
    }
}
