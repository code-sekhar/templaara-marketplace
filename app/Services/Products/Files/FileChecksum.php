<?php

namespace App\Services\Products\Files;

use Illuminate\Support\Facades\Storage;

class FileChecksum
{
    public function sha256(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Stored file is unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
