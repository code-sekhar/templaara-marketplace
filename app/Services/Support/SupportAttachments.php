<?php

namespace App\Services\Support;

use App\Services\Products\Files\FileRetention;
use App\Services\Products\Files\PrivateArtifactStorage;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportAttachments
{
    /**
     * @param array<mixed> $files
     * @return array<int,array<string,mixed>> */
    public function validate(array $files): array
    {
        $limits = app(MarketplaceSettings::class)->supportLimits();
        Validator::make(['attachments' => $files], ['attachments' => ['array', 'max:'.$limits['count']], 'attachments.*' => ['file', 'max:'.$limits['size']]])->validate();
        $out = [];
        foreach ($files as $f) {
            if (! $f instanceof UploadedFile || ! $f->isValid()) {
                $this->invalid();
            }$ext = strtolower($f->getClientOriginalExtension());
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath());
            $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'log' => 'text/plain'];
            if (! in_array($ext, $limits['types'], true) || ! isset($allowed[$ext]) || $allowed[$ext] !== $mime || $f->getSize() < 1) {
                $this->invalid();
            }$body = file_get_contents($f->getRealPath());
            if ($body === false) {
                $this->invalid();
            }
            if ($mime === 'text/plain' && (! mb_check_encoding($body, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|<\s*\/?(?:html|script|svg|iframe|\?php)|^#!|\b(?:powershell|cmd\.exe)\b/i', $body))) {
                $this->invalid();
            }
            if (str_starts_with($mime, 'image/') && ! @getimagesize($f->getRealPath())) {
                $this->invalid();
            }if ($mime === 'application/pdf' && (! str_starts_with($body, '%PDF-') || preg_match('~/JavaScript\b|/JS\b|/Launch\b|/EmbeddedFile\b|/OpenAction\b~i', $body))) {
                $this->invalid();
            }
            $out[] = ['file' => $f, 'original_name' => mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', basename(str_replace('\\', '/', $f->getClientOriginalName()))), 0, 200), 'mime_type' => $mime, 'size_bytes' => $f->getSize(), 'checksum' => hash('sha256', $body), 'extension' => $ext];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>> */
    public function stage(array $files): array
    {
        $disk = (string) config('support.attachment_disk');
        app(PrivateArtifactStorage::class)->assertSecure($disk);
        $staged = [];
        try {
            foreach ($files as $f) {
                $uuid = (string) Str::uuid();
                $path = 'support/'.$uuid.'.'.$f['extension'];
                $job = app(FileRetention::class)->schedule($disk, $path, true);
                $staged[] = [...$f, 'public_uuid' => $uuid, 'disk' => $disk, 'path' => $path, 'job' => $job];
                if (! Storage::disk($disk)->putFileAs('support', $f['file'], basename($path), ['visibility' => 'private'])) {
                    throw ValidationException::withMessages(['attachments' => 'The attachment could not be stored safely.']);
                }
            }

            return $staged;
        } catch (\Throwable $e) {
            $this->finish($staged);
            throw $e;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $files */
    public function finish(array $files): void
    {
        foreach ($files as $f) {
            app(FileRetention::class)->cleanup($f['job']);
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['attachments' => 'Use a safe PNG, JPEG, WebP, PDF, TXT or LOG file with matching content. Scripts, HTML, executable and active PDF content are not allowed.']);
    }
}
