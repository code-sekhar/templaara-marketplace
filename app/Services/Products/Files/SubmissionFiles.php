<?php

namespace App\Services\Products\Files;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Enums\ProductReleaseStatus;
use App\Enums\ProductWorkflowAction;
use App\Models\Product;
use App\Models\ProductSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmissionFiles
{
    /** @return array<string, mixed> */
    public function readiness(Product $product): array
    {
        $files = app(FilePresentation::class)->current($product);
        $cover = $product->media->where('type', ProductMediaType::Cover);
        $screens = $product->media->where('type', ProductMediaType::Screenshot);
        $errors = [];
        if ($cover->count() !== 1) {
            $errors['media.cover'] = 'Upload a product cover image before submitting.';
        }
        if ($screens->count() < 2) {
            $errors['media.screenshots'] = 'Add at least two product screenshots before submitting.';
        }
        foreach ($product->media as $file) {
            if (! Storage::disk($file->disk)->exists($file->path)) {
                $errors['media'] = 'A product image is missing. Upload it again before submitting.';
            }
        }
        $release = $product->releases->first();
        if (! $release) {
            $errors['release'] = 'Create a release package before submitting.';
        } else {
            if (! preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $release->version)) {
                $errors['release.version'] = 'Use a MAJOR.MINOR.PATCH release version.';
            }
            $package = $release->artifacts->where('is_current', true)->where('type', ProductArtifactType::SourcePackage);
            if ($package->count() !== 1) {
                $errors['release.package'] = 'Upload one private source ZIP for the prepared release.';
            }
            foreach ($release->artifacts->where('is_current', true) as $artifact) {
                if (! Storage::disk($artifact->disk)->exists($artifact->path)) {
                    $errors['release.package'] = 'A release file is missing. Upload it again before submitting.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['media' => $product->media->map(fn ($media) => app(FilePresentation::class)->media($media, url: false))->values()->all(),
            'release' => $files['releases'][0]];
    }

    /** @param array<string, mixed> $snapshot */
    public function attach(ProductSubmission $submission, array $snapshot): void
    {
        foreach ($snapshot['media'] as $media) {
            DB::table('product_submission_media')->insert(['product_submission_id' => $submission->id, 'product_media_id' => $media['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($snapshot['release']['artifacts'] as $artifact) {
            DB::table('product_submission_artifacts')->insert(['product_submission_id' => $submission->id, 'product_artifact_id' => $artifact['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function transition(Product $product, ProductSubmission $submission, ProductWorkflowAction $action): void
    {
        $releaseId = $submission->snapshot['release']['id'] ?? null;
        if (! $releaseId) {
            return;
        } // Phase 4 rounds retain their original requirements.
        $release = $product->releases()->whereKey($releaseId)->lockForUpdate()->firstOrFail();
        $status = match ($action) {
            ProductWorkflowAction::Submit, ProductWorkflowAction::StartReview => ProductReleaseStatus::InReview,
            ProductWorkflowAction::RequestChanges => ProductReleaseStatus::Draft,
            ProductWorkflowAction::Reject => ProductReleaseStatus::Rejected,
            ProductWorkflowAction::Approve => ProductReleaseStatus::Approved,
            ProductWorkflowAction::Publish => ProductReleaseStatus::Published,
        };
        $release->forceFill(['status' => $status, ...($action === ProductWorkflowAction::Publish ? ['released_at' => now()] : [])])->save();
    }
}
