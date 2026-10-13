<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorApplicationFeedback extends Model
{
    protected $table = 'author_application_feedback';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Author review history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Author review history is retained.'));
    }

    /** @return BelongsTo<AuthorApplication,$this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AuthorApplication::class, 'author_application_id');
    }
}
