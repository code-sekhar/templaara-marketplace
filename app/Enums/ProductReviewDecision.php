<?php

namespace App\Enums;

enum ProductReviewDecision: string
{
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
