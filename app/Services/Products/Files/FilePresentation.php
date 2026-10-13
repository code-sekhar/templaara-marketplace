<?php

namespace App\Services\Products\Files;

use App\Models\Product;
use App\Models\ProductArtifact;
use App\Models\ProductMedia;
use App\Models\ProductRelease;
use Illuminate\Support\Facades\Storage;

class FilePresentation
{
    /** @return array<string, mixed> */
    public function media(ProductMedia $media, bool $url = true): array
    {
        return [...$media->only(['id', 'type', 'original_name', 'mime_type', 'size_bytes', 'width', 'height', 'sort_order', 'alt_text']),
            ...($url ? ['url' => Storage::disk($media->disk)->url($media->path)] : [])];
    }

    /** @return array<string, mixed> */
    public function artifact(ProductArtifact $artifact): array
    {
        return $artifact->only(['id', 'type', 'original_name', 'mime_type', 'size_bytes', 'checksum']);
    }

    /** @return array<string, mixed> */
    public function release(ProductRelease $release): array
    {
        return [...$release->only(['id', 'version', 'status', 'changelog', 'release_notes', 'released_at']),
            'artifacts' => $release->artifacts->where('is_current', true)->map(fn ($file) => $this->artifact($file))->values()->all()];
    }

    /** @return array<string, mixed> */
    public function current(Product $product): array
    {
        $product->load(['media' => fn ($query) => $query->where('is_current', true)->orderBy('sort_order')->orderBy('id'), 'releases.artifacts']);

        return ['media' => $product->media->map(fn ($file) => $this->media($file))->all(), 'releases' => $product->releases->map(fn ($release) => $this->release($release))->all()];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function snapshot(array $snapshot): array
    {
        $ids = array_column($snapshot['media'] ?? [], 'id');
        $media = ProductMedia::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($snapshot['media'] ?? [] as $index => $item) {
            $file = $media->get($item['id']);
            $snapshot['media'][$index]['url'] = $file ? Storage::disk($file->disk)->url($file->path) : null;
        }
        $snapshot['releases'] = isset($snapshot['release']) ? [$snapshot['release']] : [];

        return $snapshot;
    }
}
