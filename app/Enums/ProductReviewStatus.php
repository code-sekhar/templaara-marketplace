<?php

namespace App\Enums;

enum ProductReviewStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
}
