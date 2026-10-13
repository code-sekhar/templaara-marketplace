<?php

namespace App\Services\Entitlements;

use App\Enums\LicenseStatus;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LicenseActivationService
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function execute(string $action, array $input): array
    {
        $keys = app(LicenseKeys::class);
        $fingerprint = $keys->fingerprint($input['license_key']);
        $identity = app(ActivationIdentity::class);
        $installation = $identity->hash(strtolower($input['installation_id']));
        $domain = $identity->domain($input['domain'] ?? null);

        return DB::transaction(function () use ($action, $input, $fingerprint, $identity, $installation, $domain) {
            $candidate = License::where('license_key_hash', $fingerprint)->first();
            if (! $candidate) {
                return $this->failure('INVALID_LICENSE');
            }
            User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $target = $candidate->entitlement;
            Order::whereKey($target->getAttribute('order_id'))->lockForUpdate()->firstOrFail();
            Entitlement::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $license = License::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($license->getAttribute('license_key_hash'), $fingerprint)) {
                return $this->failure('INVALID_LICENSE');
            }
            $e = $license->entitlement;
            if (Product::whereKey($license->product_id)->value('public_uuid') !== strtolower($input['product'])) {
                return $this->failure('PRODUCT_MISMATCH');
            }
            if ($license->status !== LicenseStatus::Active || $license->getAttribute('revoked_at') || ($license->getAttribute('expires_at') && $license->getAttribute('expires_at')->isPast()) || ! app(PurchaseAccess::class)->valid($e)) {
                return $this->failure('LICENSE_INACTIVE');
            }
            $activation = LicenseActivation::where('license_id', $license->id)->where('installation_id_hash', $installation)->lockForUpdate()->first();
            if ($activation && $activation->getAttribute('domain_hash') !== ($domain ? $identity->hash($domain) : null)) {
                return $this->failure('INSTALLATION_MISMATCH');
            }
            if ($action === 'activate') {
                if (! $activation || $activation->getAttribute('status') !== 'active') {
                    if (LicenseActivation::where('license_id', $license->id)->where('status', 'active')->count() >= $e->item->getAttribute('activation_limit')) {
                        return $this->failure('ACTIVATION_LIMIT');
                    }
                    $reactivate = (bool) $activation;
                    if (! $activation) {
                        $activation = new LicenseActivation;
                        $activation->forceFill(['public_uuid' => (string) Str::uuid(), 'license_id' => $license->id, 'entitlement_id' => $e->id, 'product_id' => $e->product_id, 'installation_id_hash' => $installation, 'installation_label' => $input['installation_label'] ?? null, 'domain_hash' => $domain ? $identity->hash($domain) : null, 'domain_display' => $domain]);
                    }
                    $activation->forceFill(['status' => 'active', 'activated_at' => now(), 'deactivated_at' => null])->save();
                    $this->audit($activation, $reactivate ? 'reactivated' : 'activated');
                }
            } elseif (! $activation) {
                return $this->failure('ACTIVATION_NOT_FOUND');
            } elseif ($action === 'deactivate') {
                $this->deactivate($activation);
            } elseif ($activation->getAttribute('status') !== 'active') {
                return $this->failure('ACTIVATION_INACTIVE');
            }
            if ($action !== 'deactivate') {
                $activation->forceFill(['last_validated_at' => now()])->save();
            }

            return ['valid' => true, 'code' => $action === 'deactivate' ? 'DEACTIVATED' : 'VALID', 'activation_id' => $activation->getAttribute('public_uuid'), 'license' => ['status' => $license->status->value, 'product' => strtolower($input['product']), 'purchased_version' => $e->item->getAttribute('version')]];
        }, 3);
    }

    public function ownerDeactivate(User $user, Entitlement $e, LicenseActivation $activation): void
    {
        Gate::forUser($user)->authorize('view', $e);
        abort_unless($activation->getAttribute('entitlement_id') === $e->id, 404);
        DB::transaction(function () use ($user, $activation) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            License::whereKey($activation->getAttribute('license_id'))->lockForUpdate()->firstOrFail();
            $a = LicenseActivation::whereKey($activation->id)->lockForUpdate()->firstOrFail();
            $this->deactivate($a, $user->id);
        });
    }

    private function deactivate(LicenseActivation $a, ?int $actor = null): void
    {
        if ($a->getAttribute('status') === 'active') {
            $a->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save();
            $this->audit($a, 'deactivated', $actor);
        }
    }

    private function audit(LicenseActivation $a, string $action, ?int $actor = null): void
    {
        DB::table('license_activation_events')->insert(['license_activation_id' => $a->id, 'action' => $action, 'actor_id' => $actor, 'occurred_at' => now()]);
    }

    /** @return array<string,mixed> */
    private function failure(string $code): array
    {
        return ['valid' => false, 'code' => $code];
    }

    /** @return array<string,mixed> */
    public function presentation(Entitlement $e, bool $detail = false): array
    {
        $e->loadMissing(['activations', 'item.product']);
        $page = $detail ? $e->activations()->paginate(20, ['*'], 'activation_page')->withQueryString() : null;
        $rows = $page ? $page->getCollection() : $e->activations;

        return ['product_identity' => $e->item->product->getAttribute('public_uuid'), 'activation_limit' => $e->item->getAttribute('activation_limit'), 'active_activations' => $e->getAttribute('active_activations_count') ?? $e->activations()->where('status', 'active')->count(), 'activation_pagination' => $page ? ['prev_page_url' => $page->previousPageUrl(), 'next_page_url' => $page->nextPageUrl(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()] : null, 'activations' => $rows->map(fn ($a) => $a->only(['public_uuid', 'installation_label', 'domain_display', 'status', 'activated_at', 'last_validated_at', 'deactivated_at']))->all()];
    }
}
