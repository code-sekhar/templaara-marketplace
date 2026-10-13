<?php

namespace App\Events\Support;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SupportMessageSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $ticketId) {}
}
