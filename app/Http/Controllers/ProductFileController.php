<?php

namespace App\Http\Controllers;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Http\Requests\Products\Files\ProductFileRequest;
use App\Models\Product;
use App\Models\ProductArtifact;
use App\Models\ProductMedia;
use App\Models\ProductRelease;
use App\Models\ProductSubmission;
use App\Services\Products\Files\ProductFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductFileController extends Controller
{
    public function mediaStore(ProductFileRequest $request, Product $product, ProductFiles $files): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(ProductMediaType::class)], 'files' => ['required', 'array'], 'files.*' => ['required', 'file'], 'replace_id' => ['nullable', 'integer']]);
        $files->uploadMedia($request->user(), $product, ProductMediaType::from($data['type']), $request->file('files'), $data['replace_id'] ?? null);

        return $this->success();
    }

    public function mediaUpdate(ProductFileRequest $request, Product $product, ProductMedia $media, ProductFiles $files): RedirectResponse
    {
        $files->updateMedia($request->user(), $product, $media, $request->all());

        return $this->success();
    }

    public function mediaDelete(ProductFileRequest $request, Product $product, ProductMedia $media, ProductFiles $files): RedirectResponse
    {
        $files->removeMedia($request->user(), $product, $media);

        return $this->success();
    }

    public function releaseStore(ProductFileRequest $request, Product $product, ProductFiles $files): RedirectResponse
    {
        $files->saveRelease($request->user(), $product, $request->all());

        return $this->success();
    }

    public function releaseUpdate(ProductFileRequest $request, Product $product, ProductRelease $release, ProductFiles $files): RedirectResponse
    {
        $files->saveRelease($request->user(), $product, $request->all(), $release);

        return $this->success();
    }

    public function artifactStore(ProductFileRequest $request, Product $product, ProductRelease $release, ProductFiles $files): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(ProductArtifactType::class)], 'file' => ['required', 'file']]);
        $files->uploadArtifact($request->user(), $product, $release, ProductArtifactType::from($data['type']), $request->file('file'));

        return $this->success();
    }

    public function artifactDelete(ProductFileRequest $request, Product $product, ProductRelease $release, ProductArtifact $artifact, ProductFiles $files): RedirectResponse
    {
        $files->removeArtifact($request->user(), $product, $release, $artifact);

        return $this->success();
    }

    public function download(Request $request, Product $product, ProductRelease $release, ProductArtifact $artifact): StreamedResponse
    {
        Gate::authorize('view', $product);
        abort_unless($release->product_id === $product->id && $artifact->product_release_id === $release->id, 404);

        return $this->stream($artifact);
    }

    public function reviewDownload(Request $request, ProductSubmission $submission, ProductArtifact $artifact): StreamedResponse
    {
        Gate::authorize('review', $submission->product);
        abort_unless($artifact->release->product_id === $submission->product_id && DB::table('product_submission_artifacts')->where('product_submission_id', $submission->id)->where('product_artifact_id', $artifact->id)->exists(), 404);

        return $this->stream($artifact);
    }

    private function stream(ProductArtifact $artifact): StreamedResponse
    {
        abort_unless(Storage::disk($artifact->disk)->exists($artifact->path), 404, 'This file is unavailable.');
        $stream = Storage::disk($artifact->disk)->readStream($artifact->path);
        abort_unless(is_resource($stream), 404, 'This file is unavailable.');

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $artifact->getAttribute('original_name'), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function success(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product files saved.']);

        return back();
    }
}
