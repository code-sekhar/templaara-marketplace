<?php

namespace App\Enums;

enum AuthorApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }
}
