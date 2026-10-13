<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Authors\PublicAuthor;
use App\Services\Reviews\ProductRatingSummary;
use Illuminate\Support\Facades\Storage;

/** Explicit allow-lists: never serialize workspace models, snapshots or artifacts. */
class PublicProduct
{
    /** @return array{url: string, alt: string}|null */
    public function media(?ProductMedia $media): ?array
    {
        if (! $media) {
            return null;
        }

        return ['url' => Storage::disk($media->disk)->url($media->path), 'alt' => $media->alt_text ?? 'Software preview'];
    }

    /** @return array<string, mixed> */
    public function card(Product $product): array
    {
        $amount = explode('.', (string) $product->getAttribute('starting_price'));

        return [
            'rating' => app(ProductRatingSummary::class)->totals((int) $product->getAttribute('rating_count'), (string) ($product->getAttribute('rating_sum') ?? '0')),
            'name' => $product->name, 'slug' => $product->slug, 'short_description' => $product->short_description,
            'official' => $product->origin->value === 'templaara', 'featured' => $product->is_featured,
            'category' => $product->category->only(['name', 'slug']),
            'industries' => $product->industries->take(3)->map->only(['name', 'slug'])->values()->all(),
            'cover' => $this->media($product->cover), 'currency' => $product->currency->value,
            'starting_price' => $amount[0].'.'.str_pad($amount[1] ?? '', 2, '0'),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Product $product): array
    {
        $release = $product->releases->first();

        return [
            ...$this->card($product),
            ...$product->only(['description', 'business_problem', 'target_audience', 'key_benefits', 'demo_username', 'demo_password', 'meta_title', 'meta_description']),
            'demo_url' => $this->external($product->demo_url),
            'documentation_url' => $this->external($product->documentation_url),
            'preview_video_url' => $this->external($product->preview_video_url),
            'author' => $product->origin->value === 'author' ? app(PublicAuthor::class)->card($product->owner) : null,
            'seller' => $product->origin->value === 'templaara' ? 'Templaara' : $product->owner?->name,
            'industries' => $product->industries->map->only(['name', 'slug'])->values()->all(),
            'technologies' => $product->technologies->map->only(['name', 'slug'])->values()->all(),
            'screenshots' => $product->screenshots->map(fn ($image) => $this->media($image))->all(),
            'features' => $product->features->map->only(['title', 'description'])->all(),
            'offers' => $product->offers->map(fn ($offer) => [
                ...$offer->only(['name', 'description', 'price', 'support_period_days', 'updates_period_days', 'activation_limit', 'is_default']),
                'license_type' => $offer->license_type->value,
                'offer_id' => $offer->id,
            ])->all(),
            'release' => $release?->only(['version', 'changelog', 'release_notes', 'released_at']),
        ];
    }

    private function external(?string $url): ?string
    {
        $scheme = $url ? parse_url($url, PHP_URL_SCHEME) : null;

        return $url && filter_var($url, FILTER_VALIDATE_URL) && is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : null;
    }
}
