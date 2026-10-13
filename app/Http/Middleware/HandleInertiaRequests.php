<?php

namespace App\Http\Middleware;

use App\Models\CartItem;
use App\Models\Category;
use App\Services\Authors\AuthorMedia;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'marketplaceNavigation' => fn () => $request->routeIs('home', 'marketplace.*')
                ? Category::active()->ordered()->get(['name', 'slug', 'description'])->toArray() : [],
            'marketplaceIdentity' => fn () => app(MarketplaceSettings::class)->identity(),
            'auth' => [
                'user' => fn () => $request->user() ? [
                    ...($request->routeIs('home', 'marketplace.*')
                        ? ['name' => $request->user()->name]
                        : $request->user()->attributesToArray()),
                    ...($request->routeIs('home', 'marketplace.*') ? [] : [
                        'roles' => $request->user()->getRoleNames()->values()->all(),
                        'permissions' => $request->user()->getAllPermissions()->pluck('name')->sort()->values()->all(),
                    ]),
                ] : null,
            ],
            'marketplaceAccount' => function () use ($request): ?array {
                $user = $request->user();
                if (! $user || ! $request->routeIs('home', 'marketplace.*')) {
                    return null;
                }

                $avatar = null;
                if ($user->hasRole('author')) {
                    $profile = $user->authorProfile;
                    if ($profile && $profile->status->value === 'approved') {
                        $avatar = app(AuthorMedia::class)->present($profile->media()->where('purpose', 'avatar')->first())['url'] ?? null;
                    }
                }

                return ['email' => $user->email, 'avatar' => $avatar,
                    'author_url' => $user->hasRole('author') && $user->can('products.view') ? route('author.dashboard', [], false) : null,
                    'admin_url' => $user->can('reports.view') ? route('admin.analytics.dashboard', [], false) : null];
            },
            'authorNavigation' => function () use ($request): ?array {
                $user = $request->user();
                if (! $user) {
                    return null;
                }
                if ($user->hasRole('author')) {
                    return ['label' => 'Author Dashboard', 'url' => route('author.dashboard')];
                }

                return ['label' => $user->authorProfile()->exists() ? 'Author Application' : 'Sell on Templaara', 'url' => route('author.onboarding.show')];
            },
            'notificationUnreadCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'cartCount' => fn () => $request->user() ? CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $request->user()->id))->count() : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
