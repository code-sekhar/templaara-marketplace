<?php

namespace App\Services\Products;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Enums\ProductReleaseStatus;
use App\Enums\ProductReviewDecision;
use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Events\Products\ProductUpdateTransitioned;
use App\Models\Product;
use App\Models\ProductArtifact;
use App\Models\ProductLifecycleEvent;
use App\Models\ProductMedia;
use App\Models\ProductRelease;
use App\Models\ProductReviewFeedback;
use App\Models\ProductRevision;
use App\Models\ProductSubmission;
use App\Models\User;
use App\Services\Products\Files\FileChecksum;
use App\Services\Products\Files\FilePresentation;
use App\Services\Products\Files\PrivateArtifactStorage;
use App\Services\Products\Files\ProductFiles;
use App\Services\Products\Files\SubmissionFiles;
use App\Services\Products\Files\UploadValidation;
use App\Services\Products\Workflow\SubmissionReadiness;
use App\Services\Products\Workflow\WorkflowInput;
use App\Services\Settings\MarketplaceSettings;
use App\Support\SemanticVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductUpdates
{
    public function create(User $actor, Product $product, string $version): ProductRevision
    {
        return DB::transaction(function () use ($actor, $product, $version) {
            $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [ProductRevision::class, $p]);
            if (ProductRevision::where('product_id', $p->id)->whereNotIn('status', ['published', 'rejected'])->exists()) {
                $this->error('Open the existing update before creating another.');
            }
            $base = $this->current($p);
            SemanticVersion::newer($version, $base->version);
            Validator::make(['version' => $version], ['version' => [Rule::unique('product_releases', 'version')->where('product_id', $p->id)]])->validate();
            $release = new ProductRelease;
            $release->forceFill(['product_id' => $p->id, 'version' => $version, 'changelog' => '', 'status' => ProductReleaseStatus::Draft, 'created_by' => $actor->id])->save();
            $r = new ProductRevision;
            $r->forceFill(['product_id' => $p->id, 'base_release_id' => $base->id, 'proposed_release_id' => $release->id, 'created_by' => $actor->id, 'status' => ProductStatus::Draft, 'content' => app(RevisionContent::class)->fromProduct($p)])->save();
            $r->media()->sync($p->media()->where('is_current', true)->pluck('id'));

            return $r;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function save(User $actor, ProductRevision $revision, array $data): void
    {
        DB::transaction(function () use ($actor, $revision, $data) {
            $settings = app(MarketplaceSettings::class);
            $settings->lock();
            if (isset($data['currency']) && ! in_array($data['currency'], $settings->currencies(), true)) {
                throw ValidationException::withMessages(['currency' => 'This currency is disabled for new commercial configuration.']);
            }
            $r = $this->locked($revision);
            Gate::forUser($actor)->authorize('update', $r);
            $content = Arr::only($data, [...app(RevisionContent::class)->fields(), 'features', 'offers', 'industry_ids', 'technology_ids']);
            foreach ($content['offers'] as &$offer) {
                $offer['activation_limit'] ??= 1;
            }unset($offer);
            $r->forceFill(['content' => $content])->save();
        });
    }

    /** @param array<string,mixed> $data */
    public function release(User $actor, ProductRevision $revision, array $data): void
    {
        DB::transaction(function () use ($actor, $revision, $data) {
            $r = $this->locked($revision);
            Gate::forUser($actor)->authorize('update', $r);
            $release = $r->release;
            SemanticVersion::newer($data['version'] ?? '', $this->current($r->product)->version);
            $valid = Validator::make($data, ['version' => ['required', 'string', Rule::unique('product_releases', 'version')->where('product_id', $r->product_id)->ignore($release->id)], 'changelog' => ['required', 'string', 'max:20000'], 'release_notes' => ['nullable', 'string', 'max:20000']])->validate();
            $release->forceFill($valid)->save();
        });
    }

    public function upload(User $actor, ProductRevision $revision, string $kind, UploadedFile $file): void
    {
        Gate::forUser($actor)->authorize('update', $revision);
        $media = in_array($kind, ['cover', 'screenshot'], true);
        $type = $media ? ProductMediaType::from($kind) : ProductArtifactType::from($kind);
        if ($media) {
            app(UploadValidation::class)->image($file, $type);
        } else {
            app(UploadValidation::class)->artifact($file, $type);
        }
        $disk = config($media ? 'product_files.media_disk' : 'product_files.artifact_disk');
        if (! $media) {
            app(PrivateArtifactStorage::class)->assertSecure($disk);
        }
        app(ProductFiles::class)->store($disk, 'products/'.$revision->product_id.'/updates/'.$revision->id, [$file], ! $media, function ($stored) use ($actor, $revision, $media, $type) {
            $r = $this->locked($revision);
            Gate::forUser($actor)->authorize('update', $r);
            if ($media) {
                if ($type === ProductMediaType::Cover) {
                    $r->media()->detach($r->media()->where('type', 'cover')->pluck('product_media.id'));
                }
                if ($type === ProductMediaType::Screenshot && $r->media()->where('type', 'screenshot')->count() >= app(MarketplaceSettings::class)->productLimit('screenshots_max')) {
                    $this->error('Remove a proposed screenshot before adding another.');
                }
                $m = new ProductMedia;
                $m->forceFill([...$stored[0], 'product_id' => $r->product_id, 'type' => $type, 'is_current' => false, 'sort_order' => $r->media()->count(), 'alt_text' => $r->content['name']])->save();
                $r->media()->attach($m->id);
            } else {
                $r->release->artifacts()->where('type', $type->value)->where('is_current', true)->update(['is_current' => false]);
                $a = new ProductArtifact;
                $a->forceFill([...$stored[0], 'product_release_id' => $r->proposed_release_id, 'type' => $type, 'checksum' => app(FileChecksum::class)->sha256($stored[0]['disk'], $stored[0]['path'])])->save();
            }
        });
    }

    public function removeMedia(User $actor, ProductRevision $revision, int $id): void
    {
        DB::transaction(function () use ($actor, $revision, $id) {
            $r = $this->locked($revision);
            Gate::forUser($actor)->authorize('update', $r);
            $r->media()->where('product_media.id', $id)->firstOrFail();
            $r->media()->detach($id);
        });
    }

    /** @param array<string,mixed> $input */
    public function transition(User $actor, ProductRevision $revision, ProductWorkflowAction $action, array $input = [], ?int $roundId = null): ProductSubmission
    {
        return DB::transaction(function () use ($actor, $revision, $action, $input, $roundId) {
            $r = $this->locked($revision);
            $p = $r->product;
            $from = $r->status;
            $ability = match ($action) {
                ProductWorkflowAction::Submit => 'submit',ProductWorkflowAction::StartReview => 'startReview',ProductWorkflowAction::RequestChanges => 'requestChanges',ProductWorkflowAction::Reject => 'reject',ProductWorkflowAction::Approve => 'approve',ProductWorkflowAction::Publish => 'publish'
            };
            Gate::forUser($actor)->authorize($ability, $action === ProductWorkflowAction::Submit ? $r : $p);
            $expected = match ($action) {
                ProductWorkflowAction::Submit => [ProductStatus::Draft, ProductStatus::ChangesRequested],ProductWorkflowAction::StartReview => [ProductStatus::Submitted],ProductWorkflowAction::Publish => [ProductStatus::Approved],default => [ProductStatus::UnderReview]
            };
            if (! in_array($from, $expected, true)) {
                $this->error('This update changed or the action is unavailable. Reload the latest round.');
            }
            $data = app(WorkflowInput::class)->validate($action, $input);
            $latest = $r->submissions()->lockForUpdate()->first();
            if ($action === ProductWorkflowAction::Submit) {
                $snapshot = $this->readiness($r);
                $round = new ProductSubmission;
                $round->forceFill(['product_id' => $p->id, 'product_revision_id' => $r->id, 'round_number' => ($p->submissions()->max('round_number') ?? 0) + 1, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'snapshot' => $snapshot, 'snapshot_version' => 3])->save();
                app(SubmissionFiles::class)->attach($round, $snapshot);
            } else {
                if (! $latest || ($roundId !== null && $latest->id !== $roundId)) {
                    $this->error('Open the latest update round to act.');
                }$round = $latest;
                if ($action === ProductWorkflowAction::StartReview) {
                    if ($round->reviewed_by) {
                        $this->error('Another reviewer claimed this round.');
                    }$round->forceFill(['reviewed_by' => $actor->id, 'review_started_at' => now()])->save();
                } elseif ($action === ProductWorkflowAction::Publish) {
                    if ($round->decision !== ProductReviewDecision::Approved) {
                        $this->error('An approved review is required.');
                    }
                    $this->verifySnapshot($r, $round);
                    if ($this->current($p)->id !== $r->base_release_id) {
                        $this->error('The live release changed; this proposal cannot be promoted.');
                    }
                    app(RevisionContent::class)->promote($r);
                } else {
                    if ($round->reviewed_by !== $actor->id || $round->decision) {
                        $this->error('Only the assigned reviewer can decide this round.');
                    }
                    if ($action === ProductWorkflowAction::Approve) {
                        $this->verifySnapshot($r, $round);
                    }
                    $decision = match ($action) {
                        ProductWorkflowAction::RequestChanges => ProductReviewDecision::ChangesRequested,ProductWorkflowAction::Reject => ProductReviewDecision::Rejected,default => ProductReviewDecision::Approved
                    };
                    $round->forceFill(['decision' => $decision, 'decision_at' => now(), 'decision_summary' => $data['summary'] ?? null])->save();
                    foreach ($data['feedback'] ?? [] as $f) {
                        (new ProductReviewFeedback)->forceFill([...$f, 'product_submission_id' => $round->id, 'created_by' => $actor->id])->save();
                    }
                }
            }
            app(SubmissionFiles::class)->transition($p, $round, $action);
            $to = match ($action) {
                ProductWorkflowAction::Submit => ProductStatus::Submitted,ProductWorkflowAction::StartReview => ProductStatus::UnderReview,ProductWorkflowAction::RequestChanges => ProductStatus::ChangesRequested,ProductWorkflowAction::Reject => ProductStatus::Rejected,ProductWorkflowAction::Approve => ProductStatus::Approved,ProductWorkflowAction::Publish => ProductStatus::Published
            };
            $r->forceFill(['status' => $to])->save();
            (new ProductLifecycleEvent)->forceFill(['product_id' => $p->id, 'product_revision_id' => $r->id, 'product_submission_id' => $round->id, 'actor_id' => $actor->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'occurred_at' => now()])->save();
            event(new ProductUpdateTransitioned($p->id, $r->id, $round->id, $actor->id, $action->value));

            return $round;
        }, 3);
    }

    public function current(Product $p): ProductRelease
    {
        return $p->releases()->where('status', 'published')->reorder()->orderByDesc('released_at')->orderByDesc('id')->lockForUpdate()->firstOrFail();
    }

    private function locked(ProductRevision $revision): ProductRevision
    {
        Product::whereKey($revision->product_id)->lockForUpdate()->firstOrFail();

        return ProductRevision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string,mixed> */
    public function readiness(ProductRevision $r): array
    {
        $release = $r->release;
        SemanticVersion::newer($release->version, $this->current($r->product)->version);
        $snapshot = app(SubmissionReadiness::class)->validate(app(RevisionContent::class)->projection($r), false, false);
        if (! $release->getAttribute('changelog')) {
            $this->error('Describe the changes for this version.');
        }
        $media = $r->media()->orderBy('sort_order')->orderBy('id')->get();
        if ($media->where('type', ProductMediaType::Cover)->count() !== 1 || $media->where('type', ProductMediaType::Screenshot)->count() < 2) {
            $this->error('Choose a cover and at least two screenshots.');
        }
        foreach ($media as $file) {
            if (! Storage::disk($file->disk)->exists($file->path)) {
                $this->error('A proposed image is missing. Upload it again.');
            }
        }
        $release->load('artifacts');
        $artifacts = $release->artifacts->where('is_current', true);
        if ($artifacts->where('type', ProductArtifactType::SourcePackage)->count() !== 1) {
            $this->error('Upload the new version source ZIP.');
        }
        foreach ($artifacts as $a) {
            app(PrivateArtifactStorage::class)->assertSecure($a->disk);
            if (! Storage::disk($a->disk)->exists($a->path) || ! hash_equals($a->getAttribute('checksum'), app(FileChecksum::class)->sha256($a->disk, $a->path))) {
                $this->error('A proposed package is missing or its checksum changed.');
            }
        }

        return [...$snapshot, 'version_label' => $release->version, 'media' => $media->map(fn ($m) => [...app(FilePresentation::class)->media($m, false), 'checksum' => app(FileChecksum::class)->sha256($m->disk, $m->path)])->all(), 'release' => app(FilePresentation::class)->release($release)];
    }

    private function verifySnapshot(ProductRevision $r, ProductSubmission $round): void
    {
        $snapshot = $this->readiness($r);
        foreach ($round->snapshot['media'] as $expected) {
            $actual = null;
            foreach ($snapshot['media'] as $candidate) {
                if ($candidate['id'] === $expected['id']) {
                    $actual = $candidate;
                }
            }if (! $actual || ! hash_equals($expected['checksum'], $actual['checksum'])) {
                $this->error('Submitted image bytes changed. Publication is blocked.');
            }
        }
        if (json_decode(json_encode($round->snapshot['release']['artifacts'], JSON_THROW_ON_ERROR), true) != json_decode(json_encode($snapshot['release']['artifacts'], JSON_THROW_ON_ERROR), true)) {
            $this->error('Submitted package identity changed. Publication is blocked.');
        }
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}
