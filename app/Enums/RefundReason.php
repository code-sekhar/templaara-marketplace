<?php

namespace App\Enums;

enum RefundReason: string
{
    case DuplicatePurchase = 'duplicate_purchase';
    case NotAsDescribed = 'product_not_as_described';
    case TechnicalIssue = 'technical_issue';
    case CustomerRequest = 'customer_request';
    case FraudOrRisk = 'fraud_or_risk';
    case Other = 'other';
}
