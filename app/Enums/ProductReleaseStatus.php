<?php

namespace App\Enums;

enum ProductReleaseStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
}
