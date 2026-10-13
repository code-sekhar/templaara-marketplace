<?php

namespace Tests\Support;

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\Files\ProductFiles;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ProductFileFixtures
{
    public static function fakeStorage(): void
    {
        foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
            Storage::set($disk, Storage::fake($disk.'_'.getmypid(), ['throw' => true]));
        }
    }

    public static function zip(string $content = 'Software fixture', string $name = 'software.zip'): File
    {
        $path = tempnam(sys_get_temp_dir(), 'templaara-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('README.txt', $content);
        $zip->close();
        $file = UploadedFile::fake()->createWithContent($name, file_get_contents($path));
        unlink($path);

        return $file;
    }

    public static function prepare(Product $product, ?User $actor = null): void
    {
        $actor ??= $product->owner ?? User::factory()->create()->assignRole('admin');
        $files = app(ProductFiles::class);
        $files->uploadMedia($actor, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('cover.jpg', 640, 360)]);
        $files->uploadMedia($actor, $product, ProductMediaType::Screenshot, [UploadedFile::fake()->image('billing.png', 640, 360), UploadedFile::fake()->image('inventory.png', 640, 360)]);
        $release = $files->saveRelease($actor, $product, ['version' => '1.0.0', 'changelog' => 'Initial business software release.']);
        $files->uploadArtifact($actor, $product, $release, ProductArtifactType::SourcePackage, self::zip());
    }
}
