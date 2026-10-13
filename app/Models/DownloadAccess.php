<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadAccess extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['accessed_at' => 'immutable_datetime', 'url_expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Download access history cannot be deleted.'));
        static::updating(fn () => throw new \LogicException('Download access history is immutable.'));
    }
}
