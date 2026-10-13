<?php

namespace App\Events\Products;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ProductUpdateTransitioned implements ShouldDispatchAfterCommit
{
    public function __construct(public int $productId, public int $revisionId, public int $submissionId, public int $actorId, public string $action) {}
}
