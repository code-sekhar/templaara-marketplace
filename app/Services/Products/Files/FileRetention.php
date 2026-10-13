<?php

namespace App\Services\Products\Files;

use App\Models\AuthorProfileMedia;
use App\Models\Entitlement;
use App\Models\ProductArtifact;
use App\Models\ProductMedia;
use App\Models\SupportAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileRetention
{
    public function referenced(ProductMedia|ProductArtifact $file): bool
    {
        if ($file instanceof ProductMedia && DB::table('product_revision_media')->where('product_media_id', $file->id)->exists()) {
            return true;
        }
        if ($file instanceof ProductArtifact && Entitlement::where('product_artifact_id', $file->id)->exists()) {
            return true;
        }

        return DB::table($file instanceof ProductMedia ? 'product_submission_media' : 'product_submission_artifacts')
            ->where($file instanceof ProductMedia ? 'product_media_id' : 'product_artifact_id', $file->id)->exists();
    }

    public function schedule(string $disk, string $path, bool $staged = false): int
    {
        DB::table('product_file_cleanup')->updateOrInsert(['disk' => $disk, 'path' => $path], ['disk' => $disk, 'path' => $path,
            'available_at' => $staged ? now()->addHour() : now(), 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('product_file_cleanup')->where('disk', $disk)->where('path', $path)->value('id');
    }

    public function retire(ProductMedia|ProductArtifact $file): void
    {
        $file->forceFill(['is_current' => false])->save();
        if ($this->referenced($file)) {
            return;
        }
        $job = $this->schedule($file->disk, $file->path);
        $file->delete();
        DB::afterCommit(fn () => $this->cleanup($job));
    }

    public function cleanup(int $job): bool
    {
        $record = DB::table('product_file_cleanup')->where('id', $job)->first();
        if (! $record) {
            return true;
        }
        // Never remove a file that became attached, including retained historical rows.
        if (AuthorProfileMedia::where('disk', $record->disk)->where('path', $record->path)->exists()
            || ProductMedia::where('disk', $record->disk)->where('path', $record->path)->exists()
            || ProductArtifact::where('disk', $record->disk)->where('path', $record->path)->exists()
            || SupportAttachment::where('disk', $record->disk)->where('path', $record->path)->exists()) {
            DB::table('product_file_cleanup')->where('id', $job)->delete();

            return true;
        }
        try {
            if (! Storage::disk($record->disk)->delete($record->path)) {
                return false;
            }
            DB::table('product_file_cleanup')->where('id', $job)->delete();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Product file cleanup will be retried.', ['cleanup_id' => $job, 'exception' => $exception::class]);

            return false;
        }
    }
}
