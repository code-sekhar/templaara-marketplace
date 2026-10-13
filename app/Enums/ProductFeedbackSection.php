<?php

namespace App\Enums;

enum ProductFeedbackSection: string
{
    case General = 'general';
    case BasicInformation = 'basic_information';
    case BusinessDetails = 'business_details';
    case Features = 'features';
    case Pricing = 'pricing';
    case Demo = 'demo';
    case Technical = 'technical';
    case Support = 'support';
    case Seo = 'seo';
}
