<?php

namespace App\Services\Authors;

use App\Models\AuthorPortfolioItem;
use App\Models\AuthorProfileMedia;
use App\Models\User;
use App\Services\Products\Files\ProductFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthorMedia
{
    public function upload(User $actor, UploadedFile $file, string $purpose, int $revision, ?AuthorPortfolioItem $portfolio = null): void
    {
        Validator::make(['file' => $file, 'purpose' => $purpose], [
            'purpose' => ['required', 'in:avatar,cover,portfolio'],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('author_professional.image_max_kb'), 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'],
        ])->validate();
        abort_unless(($purpose === 'portfolio') === ($portfolio !== null), 422);
        $disk = config('product_files.media_disk');
        if ($disk === config('product_files.artifact_disk') || config('filesystems.disks.'.$disk.'.visibility') !== 'public') {
            throw ValidationException::withMessages(['file' => 'Public presentation storage is unavailable.']);
        }
        // Existing staged-upload retention prevents storage/DB failure orphans.
        app(ProductFiles::class)->store($disk, 'authors/'.Str::uuid(), [$file], false, function (array $stored) use ($actor, $purpose, $revision, $portfolio): void {
            $profile = app(AuthorPortfolio::class)->locked($actor);
            if ($profile->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Your profile changed. Reload before uploading.']);
            }
            if ($portfolio) {
                $profile->portfolio()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
            }
            $query = $profile->media()->where('purpose', $purpose)->where('author_portfolio_item_id', $portfolio?->id);
            $query->update(['is_current' => false]);
            $data = array_intersect_key($stored[0], array_flip(['disk', 'path', 'mime_type', 'size_bytes', 'width', 'height']));
            (new AuthorProfileMedia)->forceFill([...$data, 'public_uuid' => (string) Str::uuid(), 'author_profile_id' => $profile->id, 'author_portfolio_item_id' => $portfolio?->id, 'purpose' => $purpose, 'is_current' => true])->save();
            $profile->increment('revision');
        });
    }

    /** @return array{uuid:string,url:string,alt:string}|null */
    public function present(?AuthorProfileMedia $media): ?array
    {
        if (! $media || $media->disk !== config('product_files.media_disk') || config('filesystems.disks.'.$media->disk.'.visibility') !== 'public') {
            return null;
        }

        return ['uuid' => $media->public_uuid, 'url' => Storage::disk($media->disk)->url($media->path), 'alt' => 'Professional '.($media->purpose === 'portfolio' ? 'project preview' : $media->purpose)];
    }

    public function cleanup(int $limit = 100): int
    {
        $count = 0;
        foreach (AuthorProfileMedia::where('is_current', false)->where('updated_at', '<', now()->subDay()->toIso8601String())->orderBy('id')->limit($limit)->get() as $media) {
            DB::transaction(function () use ($media, &$count): void {
                $row = AuthorProfileMedia::whereKey($media->id)->lockForUpdate()->first();
                if (! $row || $row->is_current || DB::table('author_applications')->whereRaw('CAST(snapshot AS TEXT) LIKE ?', ['%'.$row->public_uuid.'%'])->exists()) {
                    return;
                }
                if ($row->disk !== config('product_files.media_disk') || ! str_starts_with($row->path, 'authors/')) {
                    return;
                }
                if (Storage::disk($row->disk)->delete($row->path)) {
                    $row->delete();
                    $count++;
                }
            });
        }

        return $count;
    }
}
