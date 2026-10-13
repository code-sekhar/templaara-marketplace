<?php

namespace App\Services\Products\Files;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class UploadValidation
{
    public function image(UploadedFile $file, ProductMediaType $type): void
    {
        $max = app(MarketplaceSettings::class)->productLimit($type === ProductMediaType::Cover ? 'cover_max_kb' : 'screenshot_max_kb');
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:'.$max,
            $type === ProductMediaType::Cover ? 'dimensions:min_width=320,min_height=180,max_width=8000,max_height=8000' : 'dimensions:min_width=320,min_height=180,max_width=10000,max_height=10000']])->validate();
    }

    public function artifact(UploadedFile $file, ProductArtifactType $type): void
    {
        $source = $type === ProductArtifactType::SourcePackage;
        Validator::make(['file' => $file], ['file' => ['required', 'file', $source ? 'extensions:zip' : 'extensions:zip,pdf',
            $source ? 'mimes:zip' : 'mimes:zip,pdf',
            $source ? 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream' : 'mimetypes:application/zip,application/x-zip-compressed,application/pdf,application/octet-stream',
            'max:'.app(MarketplaceSettings::class)->productLimit($source ? 'package_max_kb' : 'documentation_max_kb')]])->validate();
        if (strtolower($file->getClientOriginalExtension()) === 'zip') {
            // Check archive structure without reading, extracting or executing entries.
            $archive = new ZipArchive;
            if ($archive->open($file->getPathname(), ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
                throw ValidationException::withMessages(['file' => 'Upload a valid ZIP archive.']);
            }
            try {
                if ($archive->numFiles > 50000) {
                    throw ValidationException::withMessages(['file' => 'ZIP archives may contain at most 50,000 entries.']);
                }
                $total = 0;
                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $entry = $archive->statIndex($index);
                    if ($entry === false) {
                        throw ValidationException::withMessages(['file' => 'The ZIP directory is invalid.']);
                    }
                    $name = str_replace('\\', '/', $entry['name']);
                    if (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', explode('/', $name), true)) {
                        throw ValidationException::withMessages(['file' => 'ZIP entries must use safe relative paths.']);
                    }
                    $total += $entry['size'];
                    if ($total > 2147483648 || $entry['size'] > 1073741824 || ($entry['size'] > 1048576 && $entry['size'] > max(1, $entry['comp_size']) * 1000)) {
                        throw ValidationException::withMessages(['file' => 'The ZIP expanded size or compression ratio exceeds safe inspection limits.']);
                    }
                }
            } finally {
                $archive->close();
            }
        }
    }

    public function originalName(UploadedFile $file): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'uploaded-file', 0, 255);
    }
}
