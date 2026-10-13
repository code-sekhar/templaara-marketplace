<?php

namespace App\Enums;

enum AuthorApplicationAction: string
{
    case Submit = 'submit';
    case Start = 'start';
    case Changes = 'changes';
    case Reject = 'reject';
    case Approve = 'approve';
}
