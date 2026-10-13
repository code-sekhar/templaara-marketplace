<?php

namespace App\Services\Entitlements;

use App\Models\Entitlement;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Marketplace\PublicProduct;
use App\Services\Products\Files\PrivateArtifactStorage;
use App\Services\Refunds\RefundPresentation;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Support\SupportPresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LibraryPresentation
{
    /**
     * @param  array<string,mixed>|null  $supportEntry
     * @param  array<string,mixed>|null  $reviewEntry
     * @param  array<string,mixed>|null  $refund
     * @return array<string,mixed>
     */
    public function summary(Entitlement $e, bool $admin = false, ?array $refund = null, ?array $reviewEntry = null, ?array $supportEntry = null): array
    {
        $item = $e->item;

        return ['support_entry' => $supportEntry ?? app(SupportPresentation::class)->entries(request()->user(), collect([$item]))[$item->id], 'review_entry' => $reviewEntry ?? app(ReviewPresentation::class)->entry(request()->user(), $item->product), 'refund' => ($refund ?? app(RefundPresentation::class)->item($item))['refund'], 'reference' => $e->getAttribute('public_id'), 'status' => $e->status->value, 'granted_at' => $e->getAttribute('granted_at'), 'order_number' => $item->order->getAttribute('order_number'),
            'product_name' => $item->getAttribute('product_name'), 'offer_name' => $item->getAttribute('offer_name'), 'version' => $item->getAttribute('version'), 'license_type' => $item->getAttribute('license_type'),
            ...app(LicenseActivationService::class)->presentation($e),
            'license_status' => $e->license?->status->value, 'access_count' => $e->getAttribute('accesses_count') ?? 0,
            'cover' => app(PublicProduct::class)->media($item->product?->cover),
            ...($admin ? ['customer_name' => $item->order->getAttribute('customer_name')] : [])];
    }

    /** @return array<string,mixed> */
    public function detail(Entitlement $e): array
    {
        $available = false;
        if (app(PurchaseAccess::class)->valid($e)) {
            try {
                app(PrivateArtifactStorage::class)->assertSecure($e->artifact->disk);
                $available = Storage::disk($e->artifact->disk)->exists($e->artifact->path);
            } catch (\Throwable $exception) {
                Log::warning('Purchased package availability check failed.', ['entitlement_id' => $e->id, 'exception' => $exception::class]);
            }
            if (! $available) {
                Log::warning('Retained purchased package unavailable.', ['entitlement_id' => $e->id]);
            }
        }

        return [...$this->summary($e), ...app(LicenseActivationService::class)->presentation($e, true), ...$e->item->only(['offer_description', 'support_period_days', 'updates_period_days', 'unit_price', 'currency']),
            'download_available' => $available, 'checksum' => $available ? $e->artifact->getAttribute('checksum') : null,
            'license' => $e->license ? [...$e->license->only(['issued_at', 'expires_at', 'revoked_at']), 'status' => $e->license->status->value, 'key' => $e->license->status->value === 'active' ? $e->license->getAttribute('license_secret') : null] : null];
    }

    /** @return Builder<Entitlement> */
    public function query(): Builder
    {
        return Entitlement::with(['item.order', 'item.product.cover' => fn ($q) => app(MarketplaceQuery::class)->publicMedia($q->getQuery()), 'license', 'activations' => fn ($q) => $q->limit(5)])->withCount(['accesses', 'activations as active_activations_count' => fn ($q) => $q->where('status', 'active')]);
    }
}
