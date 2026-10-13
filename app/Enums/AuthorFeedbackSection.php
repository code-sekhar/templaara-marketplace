<?php

namespace App\Enums;

enum AuthorFeedbackSection: string
{
    case General = 'general';
    case Identity = 'identity';
    case Profile = 'profile';
    case Company = 'company';
    case Portfolio = 'portfolio';
    case Contact = 'contact';
}
