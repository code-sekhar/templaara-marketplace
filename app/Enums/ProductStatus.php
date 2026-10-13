<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Published = 'published';
}
