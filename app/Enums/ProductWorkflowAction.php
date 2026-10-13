<?php

namespace App\Enums;

enum ProductWorkflowAction: string
{
    case Submit = 'submit';
    case StartReview = 'start_review';
    case RequestChanges = 'request_changes';
    case Reject = 'reject';
    case Approve = 'approve';
    case Publish = 'publish';
}
