<?php

namespace App\Services\Products\Files;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Filesystem\Path;

class PrivateArtifactStorage
{
    public function assertSecure(string $disk): void
    {
        $settings = config('filesystems.disks.'.$disk, []);
        $publicRoot = false;
        if (($settings['driver'] ?? '') === 'local') {
            $root = Path::canonicalize(realpath($settings['root'] ?? '') ?: ($settings['root'] ?? ''));
            foreach ([public_path(), storage_path('app/public')] as $presentationRoot) {
                $presentationRoot = realpath($presentationRoot) ?: $presentationRoot;
                if ($root === $presentationRoot || str_starts_with($root, rtrim($presentationRoot, '/').'/')) {
                    $publicRoot = true;
                }
            }
            if ($root === '') {
                $publicRoot = true;
            }
        }
        if (! in_array($settings['driver'] ?? '', ['local', 's3'], true) || $disk === config('product_files.media_disk') || ($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false) || isset($settings['url']) || $publicRoot) {
            throw ValidationException::withMessages(['file' => 'Private artifact storage is not configured securely.']);
        }
        $public = config('filesystems.disks.'.config('product_files.media_disk'), []);
        if (($settings['driver'] ?? '') === 's3' && ($public['driver'] ?? '') === 's3' && ($settings['bucket'] ?? null) === ($public['bucket'] ?? null) && ($settings['endpoint'] ?? null) === ($public['endpoint'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'Presentation and commercial files require separate storage buckets.']);
        }
    }
}
