<?php

namespace App\Events\Authors;

use App\Enums\AuthorApplicationStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

abstract class AuthorWorkflowEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $profileId, public readonly int $applicationId, public readonly int $actorId, public readonly AuthorApplicationStatus $fromStatus, public readonly AuthorApplicationStatus $toStatus) {}
}
