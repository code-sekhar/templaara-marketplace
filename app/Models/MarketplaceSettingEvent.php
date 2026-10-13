<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceSettingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Settings audit is immutable.'));
        static::deleting(fn () => throw new \LogicException('Settings audit is retained.'));
    }
}
