<?php

namespace App\Events\Refunds;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RefundProcessing implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $refundId) {}
}
