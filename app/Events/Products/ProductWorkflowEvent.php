<?php

namespace App\Events\Products;

use App\Enums\ProductStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

abstract class ProductWorkflowEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly int $submissionId,
        public readonly int $actorId,
        public readonly ProductStatus $fromStatus,
        public readonly ProductStatus $toStatus,
    ) {}
}
