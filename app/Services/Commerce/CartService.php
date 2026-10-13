<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductRelease;
use App\Models\User;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Marketplace\PublicProduct;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(private MarketplaceQuery $marketplace, private PublicProduct $public) {}

    /** All commerce writers use User -> Cart -> Order -> Payment lock order. Must run inside a transaction. */
    public function locked(User $user): Cart
    {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $cart = Cart::where('user_id', $user->id)->lockForUpdate()->first();
        if (! $cart) {
            $cart = new Cart;
            $cart->forceFill(['user_id' => $user->id, 'checkout_key' => (string) Str::uuid()])->save();
        }

        return $cart;
    }

    public function add(User $user, string $slug, int $offerId): void
    {
        DB::transaction(function () use ($user, $slug, $offerId): void {
            $settings = app(MarketplaceSettings::class);
            $settings->lock();
            $cart = $this->locked($user);
            $product = Product::where('slug', $slug)->lockForUpdate()->first();
            if (! $product || ! $this->marketplace->visible()->whereKey($product->id)->exists()) {
                $this->error('This software is no longer available.');
            }
            $offer = ProductOffer::whereKey($offerId)->where('product_id', $product->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $offer) {
                $this->error('Choose an active package belonging to this software.');
            }
            if (! in_array($product->currency->value, $settings->currencies(), true)) {
                $this->error('This currency is disabled for new purchases.');
            }
            if ($cart->items()->count() > $settings->cartMaximum()) {
                $this->error('Remove excess cart items before adding or confirming packages.');
            }
            if ($cart->currency && $cart->currency !== $product->currency) {
                $this->error('Your cart uses '.$cart->currency->value.'. Remove its items before adding a different currency.');
            }
            $item = $cart->items()->where('product_id', $product->id)->first();
            if (! $item && $cart->items()->count() >= $settings->cartMaximum()) {
                $this->error('Your cart has reached its item limit.');
            }
            $release = $this->marketplace->publicRelease($product->releases()->getQuery())->reorder()->orderByDesc('released_at')->orderByDesc('id')->firstOrFail();
            $item ??= new CartItem;
            $item->forceFill(['cart_id' => $cart->id, 'product_id' => $product->id, 'product_offer_id' => $offer->id, 'observed_price' => $offer->price,
                'observed_currency' => $product->currency->value, 'observed_name' => $product->name, 'terms_hash' => $this->hash($product, $offer, $release)])->save();
            $cart->forceFill(['currency' => $product->currency, 'checkout_key' => (string) Str::uuid()])->save();
        }, 3);
    }

    public function remove(User $user, int $itemId): void
    {
        DB::transaction(function () use ($user, $itemId): void {
            $cart = $this->locked($user);
            $cart->items()->whereKey($itemId)->firstOrFail()->delete();
            $cart->forceFill(['checkout_key' => (string) Str::uuid(), 'currency' => $cart->items()->exists() ? $cart->currency : null])->save();
        }, 3);
    }

    /** @return array<string,mixed> */
    public function view(User $user): array
    {
        return DB::transaction(fn () => $this->presentation($this->resolve($this->locked($user))));
    }

    /** @return array{cart: Cart, items: array<array<string,mixed>>, total: string, valid: bool} */
    public function resolve(Cart $cart, bool $lock = false): array
    {
        $items = $cart->items()->orderBy('id')->get();
        $ids = $items->pluck('product_id')->filter()->all();
        if ($lock) {
            $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'category_id']);
            Category::whereIn('id', $products->pluck('category_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            ProductOffer::whereIn('id', $items->pluck('product_offer_id')->filter())->orderBy('id')->lockForUpdate()->get(['id']);
            $this->marketplace->publicRelease(ProductRelease::whereIn('product_id', $ids))->reorder()->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $products = $this->marketplace->visible()->whereIn('products.id', $ids)->select(['products.id', 'owner_id', 'category_id', 'name', 'slug', 'currency', 'origin'])
            ->with(['category:id,name,slug', 'cover' => fn ($q) => $this->marketplace->publicMedia($q->getQuery()), 'owner:id,name',
                'offers' => fn ($q) => $q->where('is_active', true),
                'releases' => function ($q): void {
                    $this->marketplace->publicRelease($q->getQuery())->reorder()->orderByDesc('released_at')->orderByDesc('id');
                    $q->limit(1);
                }])->get()->keyBy('id');
        $settings = app(MarketplaceSettings::class);
        $policyIssue = $items->count() > $settings->cartMaximum() ? 'Remove excess items to meet the current cart limit.' : ($cart->currency && ! in_array($cart->currency->value, $settings->currencies(), true) ? 'This currency is disabled for new checkout. Your existing purchases remain available.' : null);
        $rows = [];
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            $offer = $product?->offers->firstWhere('id', $item->product_offer_id);
            $release = $product?->releases->first();
            $issue = $policyIssue;
            if (! $product || ! $offer || ! $release) {
                $issue = 'Software or selected package is no longer available. Remove it or choose another package.';
            } elseif ($product->currency !== $cart->currency) {
                $issue = 'Currency changed. Remove this item before choosing it again.';
            } elseif (Money::decimal($offer->price) !== Money::decimal($item->observed_price)) {
                $issue = 'Price changed from your previous cart value. Review the price and confirm the package again.';
            } elseif ($this->hash($product, $offer, $release) !== $item->terms_hash) {
                $issue = 'Package terms or release changed. Review and confirm the package again.';
            }
            $price = $product && $offer && $product->currency === $cart->currency ? $offer->price : $item->observed_price;
            $rows[] = ['item' => $item, 'product' => $product, 'offer' => $offer, 'release' => $release, 'issue' => $issue, 'price' => $price];
        }

        return ['cart' => $cart, 'items' => $rows, 'total' => Money::sum(array_column($rows, 'price')), 'valid' => count($rows) > 0 && ! array_filter(array_column($rows, 'issue'))];
    }

    /**
     * @param  array<string,mixed>  $quote
     * @return array<string,mixed>
     */
    public function presentation(array $quote): array
    {
        return ['currency' => $quote['cart']->currency?->value, 'checkout_key' => $quote['cart']->checkout_key, 'subtotal' => $quote['total'], 'total' => $quote['total'], 'valid' => $quote['valid'],
            'items' => array_map(fn ($row) => [
                'id' => $row['item']->id, 'slug' => $row['product']?->slug, 'name' => $row['product']->name ?? $row['item']->observed_name,
                'product_currency' => $row['product']?->currency->value,
                'offer_id' => $row['item']->product_offer_id, 'price' => $row['price'], 'previous_price' => $row['item']->observed_price, 'issue' => $row['issue'],
                'category' => $row['product']?->category?->only(['name', 'slug']),
                'cover' => $this->public->media($row['product']?->cover),
                'offers' => $row['product']?->offers->map(fn ($offer) => [...$offer->only(['name', 'price', 'support_period_days', 'updates_period_days', 'activation_limit']), 'offer_id' => $offer->id, 'license_type' => $offer->license_type->value])->all() ?? [],
            ], $quote['items'])];
    }

    public function hash(Product $product, ProductOffer $offer, ProductRelease $release): string
    {
        return hash('sha256', json_encode([$product->id, $product->name, $product->currency->value, $offer->id, $offer->name, $offer->description, Money::decimal($offer->price),
            $offer->license_type->value, $offer->support_period_days, $offer->updates_period_days, $release->id, $release->version, $offer->getAttribute('activation_limit') ?? 1], JSON_THROW_ON_ERROR));
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['cart' => $message]);
    }
}
