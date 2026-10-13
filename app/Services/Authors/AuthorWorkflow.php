<?php

namespace App\Services\Authors;

use App\Enums\AuthorApplicationAction as Action;
use App\Enums\AuthorApplicationStatus as Status;
use App\Enums\AuthorFeedbackSection;
use App\Events\Authors\AuthorApplicationApproved;
use App\Events\Authors\AuthorApplicationRejected;
use App\Events\Authors\AuthorApplicationSubmitted;
use App\Events\Authors\AuthorChangesRequested;
use App\Events\Authors\AuthorReviewStarted;
use App\Models\AuthorApplication;
use App\Models\AuthorApplicationEvent;
use App\Models\AuthorApplicationFeedback;
use App\Models\AuthorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthorWorkflow
{
    /** @param array<string,mixed> $input */
    public function transition(User $actor, AuthorProfile $target, Action $action, ?int $roundId = null, array $input = []): AuthorApplication
    {
        return DB::transaction(function () use ($actor, $target, $action, $roundId, $input): AuthorApplication {
            $applicant = User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $profile = AuthorProfile::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $latest = $profile->applications()->lockForUpdate()->first();
            if ($action === Action::Submit) {
                Gate::forUser($actor)->authorize('submit', $profile);
            } else {
                if (! $latest) {
                    abort(404);
                } Gate::forUser($actor)->authorize('review', $latest);
            }
            $rules = array_fill_keys(app(AuthorProfileInput::class)->protectedFields(), ['prohibited']);
            $rules['summary'] = $action === Action::Reject ? ['required', 'string', 'min:10', 'max:5000'] : ['nullable', 'string', 'max:5000'];
            $rules['feedback'] = $action === Action::Changes ? ['required', 'array', 'list', 'min:1', 'max:20'] : ['prohibited'];
            $rules['feedback.*'] = ['array:section,message'];
            $rules['feedback.*.section'] = ['required', Rule::enum(AuthorFeedbackSection::class)];
            $rules['feedback.*.message'] = ['required', 'string', 'min:10', 'max:5000'];
            if (is_string($input['summary'] ?? null)) {
                $input['summary'] = trim($input['summary']);
            }
            if (is_array($input['feedback'] ?? null)) {
                foreach ($input['feedback'] as &$item) {
                    if (is_array($item) && is_string($item['message'] ?? null)) {
                        $item['message'] = trim($item['message']);
                    }
                }
            }
            unset($item);
            $data = Validator::make($input, $rules, ['feedback.required' => 'Add at least one actionable feedback item.', 'summary.required' => 'Explain why this application is rejected.'])->validate();
            $from = $profile->status;
            $allowed = match ($action) {
                Action::Submit => $from->editable(),Action::Start => $from === Status::Submitted,default => $from === Status::UnderReview
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['workflow' => 'This application state has changed or this action is unavailable. Reload before trying again.']);
            }
            $to = match ($action) {
                Action::Submit => Status::Submitted,Action::Start => Status::UnderReview,Action::Changes => Status::ChangesRequested,Action::Reject => Status::Rejected,Action::Approve => Status::Approved
            };
            $now = now();
            if ($action === Action::Submit) {
                $snapshot = $profile->only(app(AuthorProfileInput::class)->fields());
                $snapshot['author_type'] = $profile->author_type?->value;
                $snapshot['professional'] = app(AuthorProfessional::class)->snapshot($profile);
                app(AuthorProfileInput::class)->validate($snapshot, $profile, true);
                app(AuthorProfessional::class)->ready($snapshot['professional']);
                $round = new AuthorApplication;
                $round->forceFill(['user_id' => $applicant->id, 'author_profile_id' => $profile->id, 'round_number' => ($latest->round_number ?? 0) + 1, 'status' => $to, 'submitted_at' => $now, 'snapshot_version' => 2, 'snapshot' => $snapshot])->save();
            } else {
                if ($latest->id !== $roundId || $latest->status !== $from) {
                    throw ValidationException::withMessages(['workflow' => 'Open the current application round before acting.']);
                }
                $round = $latest;
                if ($action === Action::Start) {
                    if ($round->reviewed_by !== null) {
                        throw ValidationException::withMessages(['workflow' => 'Another reviewer has already claimed this round.']);
                    }
                    $round->forceFill(['status' => $to, 'reviewed_by' => $actor->id, 'review_started_at' => $now])->save();
                } else {
                    if ($round->reviewed_by !== $actor->id) {
                        throw ValidationException::withMessages(['workflow' => 'Only the reviewer who started this round can decide it.']);
                    }
                    if ($action === Action::Approve) {
                        if (! $applicant->hasVerifiedEmail()) {
                            throw ValidationException::withMessages(['workflow' => 'The applicant must verify their current account email before approval.']);
                        }
                        app(AuthorProfileInput::class)->validate($round->snapshot, $profile, true);
                        if ($round->snapshot_version === 2) {
                            app(AuthorProfessional::class)->ready($round->snapshot['professional']);
                        }
                    }
                    $round->forceFill(['status' => $to, 'decision_at' => $now, 'decision_summary' => $data['summary'] ?? null])->save();
                    foreach ($data['feedback'] ?? [] as $item) {
                        (new AuthorApplicationFeedback)->forceFill([...$item, 'author_application_id' => $round->id, 'created_by' => $actor->id])->save();
                    }
                    if ($action === Action::Approve) {
                        $applicant->assignRole('author');
                    }
                }
            }
            $profile->forceFill(['status' => $to])->save();
            $eventAction = match ($action) {
                Action::Submit => $from === Status::Draft ? 'submitted' : 'resubmitted',Action::Start => 'review_started',Action::Changes => 'changes_requested',Action::Reject => 'rejected',Action::Approve => 'approved'
            };
            (new AuthorApplicationEvent)->forceFill(['author_application_id' => $round->id, 'actor_id' => $actor->id, 'action' => $eventAction, 'from_status' => $from->value, 'to_status' => $to->value, 'occurred_at' => $now])->save();
            $class = match ($action) {
                Action::Submit => AuthorApplicationSubmitted::class,Action::Start => AuthorReviewStarted::class,Action::Changes => AuthorChangesRequested::class,Action::Reject => AuthorApplicationRejected::class,Action::Approve => AuthorApplicationApproved::class
            };
            event(new $class($profile->id, $round->id, $actor->id, $from, $to));

            return $round;
        }, 3);
    }
}
