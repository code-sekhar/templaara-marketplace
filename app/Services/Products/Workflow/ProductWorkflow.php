<?php

namespace App\Services\Products\Workflow;

use App\Enums\ProductReviewDecision;
use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Events\Products\ProductApproved;
use App\Events\Products\ProductChangesRequested;
use App\Events\Products\ProductPublished;
use App\Events\Products\ProductRejected;
use App\Events\Products\ProductReviewStarted;
use App\Events\Products\ProductSubmitted;
use App\Models\Product;
use App\Models\ProductLifecycleEvent;
use App\Models\ProductReviewFeedback;
use App\Models\ProductSubmission;
use App\Models\User;
use App\Services\Products\Files\SubmissionFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductWorkflow
{
    /** @param array<string, mixed> $input */
    public function transition(User $actor, Product $product, ProductWorkflowAction $action, ?int $submissionId = null, array $input = []): ProductSubmission
    {
        return DB::transaction(function () use ($actor, $product, $action, $submissionId, $input): ProductSubmission {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $ability = match ($action) {
                ProductWorkflowAction::Submit => 'submit', ProductWorkflowAction::StartReview => 'startReview',
                ProductWorkflowAction::RequestChanges => 'requestChanges', ProductWorkflowAction::Reject => 'reject',
                ProductWorkflowAction::Approve => 'approve', ProductWorkflowAction::Publish => 'publish',
            };
            Gate::forUser($actor)->authorize($ability, $locked);
            $data = app(WorkflowInput::class)->validate($action, $input);
            $from = $locked->status;
            $to = match ($action) {
                ProductWorkflowAction::Submit => ProductStatus::Submitted,
                ProductWorkflowAction::StartReview => ProductStatus::UnderReview,
                ProductWorkflowAction::RequestChanges => ProductStatus::ChangesRequested,
                ProductWorkflowAction::Reject => ProductStatus::Rejected,
                ProductWorkflowAction::Approve => ProductStatus::Approved,
                ProductWorkflowAction::Publish => ProductStatus::Published,
            };
            $allowed = match ($action) {
                ProductWorkflowAction::Submit => in_array($from, [ProductStatus::Draft, ProductStatus::ChangesRequested], true),
                ProductWorkflowAction::StartReview => $from === ProductStatus::Submitted,
                ProductWorkflowAction::RequestChanges, ProductWorkflowAction::Reject, ProductWorkflowAction::Approve => $from === ProductStatus::UnderReview,
                ProductWorkflowAction::Publish => $from === ProductStatus::Approved,
            };
            if ($action === ProductWorkflowAction::StartReview && $from === ProductStatus::UnderReview) {
                throw ValidationException::withMessages(['workflow' => 'Another reviewer has already started this round. Only its assigned reviewer can decide it.']);
            }
            if (! $allowed) {
                throw ValidationException::withMessages(['workflow' => 'The product state has changed or this action is unavailable. Reload before trying again.']);
            }
            $latest = $locked->submissions()->whereNull('product_revision_id')->lockForUpdate()->first();
            $now = now();
            if ($action === ProductWorkflowAction::Submit) {
                $snapshot = app(SubmissionReadiness::class)->validate($locked);
                $submission = new ProductSubmission;
                $submission->forceFill([
                    'product_id' => $locked->id, 'round_number' => ($latest->round_number ?? 0) + 1,
                    'submitted_by' => $actor->id, 'submitted_at' => $now,
                    'snapshot' => $snapshot, 'snapshot_version' => 2,
                ])->save();
            } else {
                if (! $latest || ($submissionId !== null && $latest->id !== $submissionId)) {
                    throw ValidationException::withMessages(['workflow' => 'This is an older review round. Open the latest submission to act.']);
                }
                $submission = $latest;
                if ($action === ProductWorkflowAction::StartReview) {
                    if ($submission->reviewed_by !== null) {
                        throw ValidationException::withMessages(['workflow' => 'Another reviewer has already started this review.']);
                    }
                    $submission->forceFill(['reviewed_by' => $actor->id, 'review_started_at' => $now])->save();
                } elseif ($action !== ProductWorkflowAction::Publish) {
                    if ($submission->reviewed_by !== $actor->id || $submission->decision !== null) {
                        throw ValidationException::withMessages(['workflow' => 'Only the reviewer who started this round can record its decision.']);
                    }
                    if ($action === ProductWorkflowAction::Approve) {
                        app(SubmissionReadiness::class)->validate($locked, requireFiles: $submission->getAttribute('snapshot_version') >= 2);
                    }
                    $decision = match ($action) {
                        ProductWorkflowAction::RequestChanges => ProductReviewDecision::ChangesRequested,
                        ProductWorkflowAction::Reject => ProductReviewDecision::Rejected,
                        default => ProductReviewDecision::Approved,
                    };
                    $submission->forceFill(['decision' => $decision, 'decision_at' => $now, 'decision_summary' => $data['summary'] ?? null])->save();
                    foreach ($data['feedback'] ?? [] as $item) {
                        $feedback = new ProductReviewFeedback;
                        $feedback->forceFill([...$item, 'product_submission_id' => $submission->id, 'created_by' => $actor->id])->save();
                    }
                } else {
                    if ($submission->decision !== ProductReviewDecision::Approved) {
                        throw ValidationException::withMessages(['workflow' => 'Only an approved review decision can be published.']);
                    }
                    app(SubmissionReadiness::class)->validate($locked, requireFiles: $submission->getAttribute('snapshot_version') >= 2);
                }
            }
            if ($action === ProductWorkflowAction::Submit) {
                app(SubmissionFiles::class)->attach($submission, $snapshot);
            }
            app(SubmissionFiles::class)->transition($locked, $submission, $action);
            $locked->forceFill(['status' => $to])->save();
            $history = new ProductLifecycleEvent;
            $history->forceFill([
                'product_id' => $locked->id, 'product_submission_id' => $submission->id,
                'actor_id' => $actor->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'occurred_at' => $now,
            ])->save();
            $eventClass = match ($action) {
                ProductWorkflowAction::Submit => ProductSubmitted::class,
                ProductWorkflowAction::StartReview => ProductReviewStarted::class,
                ProductWorkflowAction::RequestChanges => ProductChangesRequested::class,
                ProductWorkflowAction::Reject => ProductRejected::class,
                ProductWorkflowAction::Approve => ProductApproved::class,
                ProductWorkflowAction::Publish => ProductPublished::class,
            };
            event(new $eventClass($locked->id, $submission->id, $actor->id, $from, $to));

            return $submission;
        });
    }
}
