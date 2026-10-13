<?php

namespace App\Events\Settings;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class MarketplaceSettingChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $group, public readonly string $key, public readonly int $actorId, public readonly mixed $oldValue, public readonly mixed $newValue, public readonly string $occurredAt) {}
}
