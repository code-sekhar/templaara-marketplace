<?php

namespace App\Enums;

enum SupportCategory: string
{
    case Installation = 'installation';
    case Configuration = 'configuration';
    case Bug = 'bug';
    case UsageQuestion = 'usage_question';
    case Compatibility = 'compatibility';
    case LicenseActivation = 'license_activation';
    case Update = 'update';
    case Other = 'other';
}
