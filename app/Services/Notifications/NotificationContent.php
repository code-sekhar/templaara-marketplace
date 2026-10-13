<?php

namespace App\Services\Notifications;

use App\Enums\MarketplaceNotificationType as Type;

class NotificationContent
{
    /** @return array{title:string,message:string} */
    public function text(Type $type): array
    {
        [$title,$message] = match ($type) {
            Type::PurchaseReady => ['Your Templaara purchase is ready', 'Payment succeeded and your purchased software access is ready.'],
            Type::RefundRequested => ['Refund request received', 'Your refund request has been recorded for review. No refund has been completed yet.'],
            Type::RefundApproved => ['Refund request approved', 'Your refund was approved. Processing and completion are still pending.'],
            Type::RefundProcessing => ['Refund is processing', 'Your refund is being processed. Completion has not yet been confirmed.'],
            Type::RefundCompleted => ['Your refund has been completed', 'Your refund completion has been confirmed. View the request for its recorded details.'],
            Type::RefundFailed => ['Refund processing failed', 'Your refund attempt did not complete. View the request for its current status.'],
            Type::RefundCancelled => ['Refund request cancelled', 'Your refund request was cancelled.'],
            Type::ApplicationSubmitted => ['Author application submitted', 'Your author application has entered review.'],
            Type::ApplicationChangesRequested => ['Changes requested for your author application', 'Open your application to see the changes required before resubmitting.'],
            Type::ApplicationApproved => ['Author application approved', 'Your author application was approved.'],
            Type::ApplicationRejected => ['Author application rejected', 'Your author application was rejected. Open the application to view the decision.'],
            Type::ProductSubmitted => ['Product submitted for review', 'Your product submission has entered review.'],
            Type::ProductChangesRequested => ['Changes requested for your product', 'Open your product to see the feedback before resubmitting.'],
            Type::ProductApproved => ['Your product has been approved', 'Your product passed review. Publication is a separate action.'],
            Type::ProductRejected => ['Your product was rejected', 'Open your product to view the review decision.'],
            Type::ProductPublished => ['Your product has been published', 'Your product has reached the published lifecycle state.'],
            Type::UpdateSubmitted => ['Product update submitted', 'Your product update has entered review.'],
            Type::UpdateChangesRequested => ['Changes requested for your product update', 'Open your product update to see the feedback before resubmitting.'],
            Type::UpdateApproved => ['Product update approved', 'Your product update passed review. Publication is a separate action.'],
            Type::UpdateRejected => ['Product update rejected', 'Open your product update to view the review decision.'],
            Type::UpdatePublished => ['Product update published', 'Your approved product update has been published.'],
            Type::EarningRecorded => ['Author earning recorded', 'An earning has been recorded. Open your earnings to see its current availability.'],
            Type::EarningAvailable => ['Author earning became available', 'An earning moved from its hold period to available funds.'],
            Type::PayoutRequested => ['Payout requested', 'Your payout request has been recorded for review.'],
            Type::PayoutApproved => ['Payout approved', 'Your payout was approved. It has not yet been confirmed as paid.'],
            Type::PayoutProcessing => ['Payout is processing', 'Your payout is being processed. Payment has not yet been confirmed.'],
            Type::PayoutPaid => ['Your payout has been completed', 'Your payout was confirmed as paid. View its recorded details.'],
            Type::PayoutFailed => ['Payout processing failed', 'Your payout attempt did not complete. View its current status.'],
            Type::PayoutCancelled => ['Payout cancelled', 'Your payout request was cancelled.'],
            Type::ReviewReceived => ['New verified customer review', 'Your software received a verified public customer review.'],
            Type::ReviewResponse => ['Seller responded to your review', 'The seller has responded to your review.'],
            Type::SupportCreated => ['New product support ticket', 'A customer opened a support ticket for purchased software.'],
            Type::SupportCustomerReply => ['Customer replied on a support ticket', 'There is a new customer reply. Open the ticket to read it securely.'],
            Type::SupportSellerReply => ['New reply on your support ticket', 'There is a new seller or staff reply. Open the ticket to read it securely.'],
            Type::SupportResolved => ['Your support ticket was resolved', 'Your support ticket was marked resolved. Open it to review the conversation.'],
            Type::SupportClosed => ['Your support ticket was closed', 'Your support ticket was closed. Its conversation remains available to you.'],
            Type::SupportEscalated => ['Support ticket escalated', 'A support ticket was escalated for staff attention.'],
            Type::SupportDeescalated => ['Support escalation cleared', 'The escalation on a support ticket was cleared.'],
        };

        return ['title' => $title, 'message' => $message];
    }
}
