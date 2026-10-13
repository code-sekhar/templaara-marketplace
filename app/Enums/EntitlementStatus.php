<?php

namespace App\Enums;

enum EntitlementStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
