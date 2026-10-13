<?php

namespace App\Events\Payouts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PayoutProcessing implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $payoutId) {}
}
