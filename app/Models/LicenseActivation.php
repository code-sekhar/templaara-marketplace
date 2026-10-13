<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['installation_id_hash', 'domain_hash'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['activated_at' => 'immutable_datetime', 'last_validated_at' => 'immutable_datetime', 'deactivated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Activation history cannot be deleted.'));
        static::updating(function (self $a) {
            if (array_diff(array_keys($a->getDirty()), ['status', 'activated_at', 'last_validated_at', 'deactivated_at', 'updated_at'])) {
                throw new \LogicException('Activation identity is immutable.');
            }
        });
    }
}
