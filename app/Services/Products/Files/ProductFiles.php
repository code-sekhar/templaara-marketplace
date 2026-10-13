<?php

namespace App\Services\Products\Files;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Enums\ProductReleaseStatus;
use App\Models\Product;
use App\Models\ProductArtifact;
use App\Models\ProductMedia;
use App\Models\ProductRelease;
use App\Models\User;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductFiles
{
    /** @param array<int, UploadedFile> $files */
    public function uploadMedia(User $actor, Product $product, ProductMediaType $type, array $files, ?int $replaceId = null): void
    {
        Gate::forUser($actor)->authorize('update', $product);
        Validator::make(['files' => $files], ['files' => ['required', 'array', 'min:1', 'max:'.($type === ProductMediaType::Cover || $replaceId ? 1 : app(MarketplaceSettings::class)->productLimit('screenshots_max'))]])->validate();
        foreach ($files as $file) {
            app(UploadValidation::class)->image($file, $type);
        }
        $disk = config('product_files.media_disk');
        $this->store($disk, 'products/'.$product->id.'/media', $files, false, function (array $stored) use ($actor, $product, $type, $replaceId): void {
            $locked = $this->editable($actor, $product);
            $old = $replaceId ? $locked->media()->whereKey($replaceId)->where('is_current', true)->firstOrFail() : null;
            if ($old && $old->getAttribute('type') !== $type) {
                abort(422, 'Replacement must have the same media type.');
            }
            if ($type === ProductMediaType::Cover) {
                $old = $locked->media()->where('type', 'cover')->where('is_current', true)->first();
            }
            $count = $locked->media()->where('type', 'screenshot')->where('is_current', true)->count();
            if ($type === ProductMediaType::Screenshot && $count + count($stored) - ($old ? 1 : 0) > app(MarketplaceSettings::class)->productLimit('screenshots_max')) {
                throw ValidationException::withMessages(['files' => 'A product may have at most '.app(MarketplaceSettings::class)->productLimit('screenshots_max').' screenshots.']);
            }
            $order = $old?->getAttribute('sort_order') ?? (($locked->media()->where('is_current', true)->max('sort_order') ?? -1) + 1);
            $alt = $old?->getAttribute('alt_text');
            if ($old) {
                app(FileRetention::class)->retire($old);
            }
            foreach ($stored as $data) {
                $media = new ProductMedia;
                $media->forceFill([...$data, 'product_id' => $locked->id, 'type' => $type, 'sort_order' => $order++, 'alt_text' => $alt])->save();
            }
        });
    }

    public function removeMedia(User $actor, Product $product, ProductMedia $media): void
    {
        DB::transaction(function () use ($actor, $product, $media): void {
            $locked = $this->editable($actor, $product);
            $file = $locked->media()->whereKey($media->id)->where('is_current', true)->firstOrFail();
            app(FileRetention::class)->retire($file);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateMedia(User $actor, Product $product, ProductMedia $media, array $data): void
    {
        DB::transaction(function () use ($actor, $product, $media, $data): void {
            $locked = $this->editable($actor, $product);
            $file = $locked->media()->whereKey($media->id)->where('is_current', true)->firstOrFail();
            $validated = Validator::make($data, ['sort_order' => ['required', 'integer', 'min:0', 'max:1000000'], 'alt_text' => ['nullable', 'string', 'max:500']])->validate();
            $file->forceFill($validated)->save();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRelease(User $actor, Product $product, array $data, ?ProductRelease $release = null): ProductRelease
    {
        return DB::transaction(function () use ($actor, $product, $data, $release): ProductRelease {
            $locked = $this->editable($actor, $product);
            $record = $release ? $locked->releases()->whereKey($release->id)->firstOrFail() : new ProductRelease;
            if ($release && $record->status !== ProductReleaseStatus::Draft) {
                abort(403, 'Only a draft release can be edited.');
            }
            $validated = Validator::make($data, [
                'version' => ['required', 'string', 'max:50', 'regex:/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', Rule::unique('product_releases', 'version')->where('product_id', $locked->id)->ignore($record->id)],
                'changelog' => ['required', 'string', 'max:20000'], 'release_notes' => ['nullable', 'string', 'max:20000'],
            ])->validate();
            if (! $release) {
                $record->forceFill(['product_id' => $locked->id, 'created_by' => $actor->id, 'status' => ProductReleaseStatus::Draft]);
            }
            $record->forceFill($validated)->save();

            return $record;
        });
    }

    public function uploadArtifact(User $actor, Product $product, ProductRelease $release, ProductArtifactType $type, UploadedFile $file): void
    {
        Gate::forUser($actor)->authorize('update', $product);
        abort_unless($release->product_id === $product->id && $release->status === ProductReleaseStatus::Draft, 403);
        app(UploadValidation::class)->artifact($file, $type);
        $disk = config('product_files.artifact_disk');
        app(PrivateArtifactStorage::class)->assertSecure($disk);
        $checksum = hash_file('sha256', $file->getPathname());
        if ($checksum === false) {
            throw ValidationException::withMessages(['file' => 'Unable to verify this file. Please upload it again.']);
        }
        $this->store($disk, 'products/'.$product->id.'/releases/'.$release->id, [$file], true, function (array $stored) use ($actor, $product, $release, $type, $checksum): void {
            $locked = $this->editable($actor, $product);
            $record = $locked->releases()->whereKey($release->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === ProductReleaseStatus::Draft, 403);
            $old = $record->artifacts()->where('type', $type)->where('is_current', true)->first();
            if ($old) {
                app(FileRetention::class)->retire($old);
            }
            $artifact = new ProductArtifact;
            $artifact->forceFill([...$stored[0], 'checksum' => $checksum, 'type' => $type, 'product_release_id' => $record->id])->save();
        });
    }

    public function removeArtifact(User $actor, Product $product, ProductRelease $release, ProductArtifact $artifact): void
    {
        DB::transaction(function () use ($actor, $product, $release, $artifact): void {
            $locked = $this->editable($actor, $product);
            $record = $locked->releases()->whereKey($release->id)->firstOrFail();
            abort_unless($record->status === ProductReleaseStatus::Draft, 403);
            $file = $record->artifacts()->whereKey($artifact->id)->where('is_current', true)->firstOrFail();
            app(FileRetention::class)->retire($file);
        });
    }

    private function editable(User $actor, Product $product): Product
    {
        $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $locked);

        return $locked;
    }

    /** @param array<int, UploadedFile> $files */
    public function store(string $disk, string $directory, array $files, bool $private, callable $persist): void
    {
        $jobs = [];
        $stored = [];
        try {
            foreach ($files as $file) {
                $extension = $private ? strtolower($file->getClientOriginalExtension()) : match ($file->getMimeType()) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp'
                };
                $name = Str::uuid().'.'.$extension;
                $path = $directory.'/'.$name;
                $jobs[] = app(FileRetention::class)->schedule($disk, $path, staged: true);
                $saved = Storage::disk($disk)->putFileAs($directory, $file, $name, ['visibility' => $private ? 'private' : 'public']);
                if (! $saved) {
                    throw new \RuntimeException('Storage rejected the upload.');
                }
                if (DB::transactionLevel() > 0) {
                    DB::afterRollBack(function () use ($disk, $path): void {
                        $job = app(FileRetention::class)->schedule($disk, $path);
                        app(FileRetention::class)->cleanup($job);
                    });
                }
                $data = ['disk' => $disk, 'path' => $path, 'original_name' => app(UploadValidation::class)->originalName($file), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'is_current' => true];
                if (! $private) {
                    $dimensions = getimagesize($file->getPathname());
                    if ($dimensions === false) {
                        throw new \RuntimeException('Image dimensions are unavailable.');
                    }
                    [$data['width'], $data['height']] = $dimensions;
                }
                $stored[] = $data;
            }
            DB::transaction(function () use ($persist, $stored, $jobs): void {
                $persist($stored);
                DB::table('product_file_cleanup')->whereIn('id', $jobs)->delete();
            });
        } catch (\Throwable $exception) {
            foreach ($jobs as $job) {
                app(FileRetention::class)->cleanup($job);
            }
            throw $exception;
        }
    }
}
