<?php

namespace App\Events\Reviews;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ProductReviewCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $reviewId) {}
}
