<?php

namespace App\Services\Entitlements;

use App\Models\DownloadAccess;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Products\Files\PrivateArtifactStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class EntitlementDownload
{
    public function deliver(User $user, Entitlement $target): Response
    {
        return DB::transaction(function () use ($user, $target): Response {
            $e = Entitlement::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('download', $e);
            $artifact = $e->artifact;
            $stream = null;
            try {
                app(PrivateArtifactStorage::class)->assertSecure($artifact->disk);
                $disk = Storage::disk($artifact->disk);
                if (! $disk->exists($artifact->path)) {
                    throw new \RuntimeException('Missing retained package.');
                }
                $filename = basename(str_replace('\\', '/', $artifact->getAttribute('original_name')));
                $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                if (! $filename || in_array($filename, ['.', '..'], true)) {
                    $filename = 'software.zip';
                }
                $headers = ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
                $temporary = config('entitlements.temporary_urls') && config('filesystems.disks.'.$artifact->disk.'.driver') === 's3' && $disk->providesTemporaryUrls();
                $expires = $temporary ? now()->addMinutes(max(1, min(10, (int) config('entitlements.url_minutes')))) : null;
                if ($temporary) {
                    $url = $disk->temporaryUrl($artifact->path, $expires, ['ResponseContentType' => 'application/octet-stream', 'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', $filename)]);
                    if (! str_starts_with($url, 'https://')) {
                        throw new \RuntimeException('Temporary delivery requires HTTPS.');
                    }
                    $response = redirect()->away($url, 302, $headers);
                } else {
                    $stream = $disk->readStream($artifact->path);
                    if (! is_resource($stream)) {
                        throw new \RuntimeException('Unable to prepare package stream.');
                    }
                    $response = response()->streamDownload(function () use ($stream): void {
                        try {
                            fpassthru($stream);
                        } finally {
                            fclose($stream);
                        }
                    }, $filename, $headers);
                }
            } catch (\Throwable $exception) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                Log::warning('Purchased package delivery is temporarily unavailable.', ['entitlement_id' => $e->id, 'exception' => $exception::class]);
                abort(503, 'Download temporarily unavailable. Please try again later.');
            }
            try {
                (new DownloadAccess)->forceFill(['entitlement_id' => $e->id, 'user_id' => $user->id, 'order_item_id' => $e->order_item_id, 'product_release_id' => $e->product_release_id,
                    'product_artifact_id' => $artifact->id, 'delivery' => $temporary ? 'temporary_url' : 'stream', 'accessed_at' => now(), 'url_expires_at' => $expires])->save();
            } catch (\Throwable $exception) {
                if (is_resource($stream)) {
                    fclose($stream);
                } throw $exception;
            }

            return $response;
        }, 3);
    }
}
