<?php

namespace App\Events\Reviews;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ProductReviewResponseUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $reviewId) {}
}
