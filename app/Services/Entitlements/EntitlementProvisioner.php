<?php

namespace App\Services\Entitlements;

use App\Enums\EntitlementStatus;
use App\Enums\LicenseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductArtifactType;
use App\Enums\ProductReleaseStatus;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\Order;
use App\Models\ProductArtifact;
use App\Models\ProductRelease;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EntitlementProvisioner
{
    public function __construct(private LicenseKeys $keys) {}

    /** @return array{entitlements:int,licenses:int} Newly created rows only; existing keys are preserved. */
    public function provision(Order $target): array
    {
        return DB::transaction(function () use ($target): array {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $settlement = $order->payments()->whereKey($order->getAttribute('settled_payment_id'))->first();
            if ($order->status !== OrderStatus::Completed || $order->payment_status !== PaymentStatus::Paid || ! $settlement || $settlement->status !== PaymentStatus::Paid || $settlement->amount !== $order->grand_total || $settlement->currency !== $order->currency->value) {
                throw ValidationException::withMessages(['entitlement' => 'Only verified paid, completed orders can provision software access.']);
            }
            $counts = ['entitlements' => 0, 'licenses' => 0];
            foreach ($order->items()->orderBy('id')->get() as $item) {
                $entitlement = Entitlement::where('order_item_id', $item->id)->lockForUpdate()->first();
                if (! $entitlement) {
                    $release = ProductRelease::whereKey($item->product_release_id)->lockForUpdate()->firstOrFail();
                    if ($release->product_id !== $item->product_id || $release->version !== $item->getAttribute('version') || $release->status !== ProductReleaseStatus::Published) {
                        throw ValidationException::withMessages(['entitlement' => 'The purchased release metadata requires operational review.']);
                    }
                    // Approved submission references are immutable and include retired historical files.
                    $packages = ProductArtifact::where('product_release_id', $release->id)->where('type', ProductArtifactType::SourcePackage)
                        ->whereExists(fn ($q) => $q->selectRaw('1')->from('product_submission_artifacts as f')->join('product_submissions as s', 's.id', '=', 'f.product_submission_id')->whereColumn('f.product_artifact_id', 'product_artifacts.id')->where('s.decision', 'approved'))->lockForUpdate()->get();
                    if ($packages->isEmpty()) {
                        $packages = ProductArtifact::where('product_release_id', $release->id)->where('type', ProductArtifactType::SourcePackage)->lockForUpdate()->get();
                    }
                    if ($packages->count() !== 1) {
                        throw ValidationException::withMessages(['entitlement' => 'The exact purchased source package cannot be identified safely.']);
                    }
                    $entitlement = new Entitlement;
                    $entitlement->forceFill(['public_id' => (string) Str::uuid(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'order_item_id' => $item->id,
                        'product_id' => $item->product_id, 'product_offer_id' => $item->product_offer_id, 'product_release_id' => $release->id, 'product_artifact_id' => $packages->first()->id, 'status' => EntitlementStatus::Active, 'granted_at' => $order->getAttribute('paid_at')])->save();
                    $counts['entitlements']++;
                }
                if (! $entitlement->license()->exists()) {
                    $key = $this->keys->generate();
                    (new License)->forceFill(['entitlement_id' => $entitlement->id, 'user_id' => $entitlement->user_id, 'order_item_id' => $entitlement->order_item_id,
                        'product_id' => $entitlement->product_id, 'product_release_id' => $entitlement->product_release_id, 'license_secret' => $key, 'license_key_hash' => $this->keys->fingerprint($key),
                        'license_key_last4' => substr($key, -4), 'status' => LicenseStatus::from($entitlement->status->value), 'issued_at' => now(), 'revoked_at' => $entitlement->getAttribute('revoked_at'), 'expires_at' => $entitlement->getAttribute('expires_at')])->save();
                    $counts['licenses']++;
                }
            }

            return $counts;
        }, 3);
    }
}
