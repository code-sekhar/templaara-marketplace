<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $author_user_id
 */
class AuthorPayoutMethod extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['details_encrypted', 'details_fingerprint'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['details_encrypted' => 'encrypted:array', 'is_default' => 'boolean', 'disabled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Payout methods are retained; disable instead.'));
        static::updating(function (self $m) {
            if ($m->isDirty(['author_user_id', 'author_profile_id', 'public_uuid', 'type', 'currency', 'created_at'])) {
                throw new \LogicException('Method identity is immutable.');
            }
        });
    }
}
