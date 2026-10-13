<?php

namespace App\Enums;

enum MarketplaceNotificationType: string
{
    case PurchaseReady = 'purchase_ready';
    case RefundRequested = 'refund_requested';
    case RefundApproved = 'refund_approved';
    case RefundProcessing = 'refund_processing';
    case RefundCompleted = 'refund_completed';
    case RefundFailed = 'refund_failed';
    case RefundCancelled = 'refund_cancelled';
    case ApplicationSubmitted = 'application_submitted';
    case ApplicationChangesRequested = 'application_changes_requested';
    case ApplicationApproved = 'application_approved';
    case ApplicationRejected = 'application_rejected';
    case ProductSubmitted = 'product_submitted';
    case ProductChangesRequested = 'product_changes_requested';
    case ProductApproved = 'product_approved';
    case ProductRejected = 'product_rejected';
    case ProductPublished = 'product_published';
    case UpdateSubmitted = 'update_submitted';
    case UpdateChangesRequested = 'update_changes_requested';
    case UpdateApproved = 'update_approved';
    case UpdateRejected = 'update_rejected';
    case UpdatePublished = 'update_published';
    case EarningRecorded = 'earning_recorded';
    case EarningAvailable = 'earning_available';
    case PayoutRequested = 'payout_requested';
    case PayoutApproved = 'payout_approved';
    case PayoutProcessing = 'payout_processing';
    case PayoutPaid = 'payout_paid';
    case PayoutFailed = 'payout_failed';
    case PayoutCancelled = 'payout_cancelled';
    case ReviewReceived = 'review_received';
    case ReviewResponse = 'review_response';
    case SupportCreated = 'support_created';
    case SupportCustomerReply = 'support_customer_reply';
    case SupportSellerReply = 'support_seller_reply';
    case SupportResolved = 'support_resolved';
    case SupportClosed = 'support_closed';
    case SupportEscalated = 'support_escalated';
    case SupportDeescalated = 'support_deescalated';

    public function group(): string
    {
        return match ($this) {
            self::PurchaseReady => 'orders',
            self::RefundRequested, self::RefundApproved, self::RefundProcessing, self::RefundCompleted, self::RefundFailed, self::RefundCancelled => 'refunds',
            self::ApplicationSubmitted, self::ApplicationChangesRequested, self::ApplicationApproved, self::ApplicationRejected, self::ProductSubmitted, self::ProductChangesRequested, self::ProductApproved, self::ProductRejected, self::ProductPublished => 'author_workflow',
            self::UpdateSubmitted, self::UpdateChangesRequested, self::UpdateApproved, self::UpdateRejected, self::UpdatePublished => 'product_updates',
            self::EarningRecorded, self::EarningAvailable => 'earnings',
            self::PayoutRequested, self::PayoutApproved, self::PayoutProcessing, self::PayoutPaid, self::PayoutFailed, self::PayoutCancelled => 'payouts',
            self::ReviewReceived, self::ReviewResponse => 'reviews',
            self::SupportCreated, self::SupportCustomerReply, self::SupportSellerReply, self::SupportResolved, self::SupportClosed, self::SupportEscalated, self::SupportDeescalated => 'support',
        };
    }

    public function mandatory(): bool
    {
        return in_array($this, [self::PurchaseReady, self::RefundCompleted, self::PayoutPaid], true);
    }

    public function email(): bool
    {
        return in_array($this, [self::PurchaseReady, self::RefundCompleted, self::RefundFailed, self::ApplicationChangesRequested, self::ApplicationApproved, self::ApplicationRejected, self::ProductChangesRequested, self::ProductApproved, self::ProductRejected, self::ProductPublished, self::UpdateChangesRequested, self::UpdateApproved, self::UpdateRejected, self::UpdatePublished, self::PayoutPaid, self::PayoutFailed, self::SupportCreated, self::SupportCustomerReply, self::SupportSellerReply, self::SupportResolved, self::ReviewReceived, self::ReviewResponse], true);
    }
}
