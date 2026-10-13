<?php

namespace App\Events\Commerce;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuthorEarningAvailable implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $earningId) {}
}
