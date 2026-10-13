<?php

namespace App\Enums;

enum LicenseType: string
{
    case SingleBusiness = 'single_business';
    case MultiBusiness = 'multi_business';
    case Extended = 'extended';
}
