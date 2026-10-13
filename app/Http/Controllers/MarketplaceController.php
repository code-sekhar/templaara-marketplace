<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Industry;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Marketplace\PublicProduct;
use App\Services\Reviews\ProductRatingSummary;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Support\SupportEligibility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends Controller
{
    public function __construct(private MarketplaceQuery $query, private PublicProduct $public) {}

    public function home(): Response
    {
        return $this->page('Home', [
            ...$this->query->facets(),
            'featured' => $this->query->cards()->where('is_featured', true)->orderByDesc('published_at')->orderByDesc('products.id')->limit(6)->get()->map(fn ($p) => $this->public->card($p)),
            'recent' => $this->query->cards()->orderByDesc('published_at')->orderByDesc('products.id')->limit(6)->get()->map(fn ($p) => $this->public->card($p)),
        ], 'Software built for your business', 'Discover ready business software, explore clear features and pricing, and try live demos.', route('home'));
    }

    public function index(Request $request): Response
    {
        return $this->listing($request);
    }

    public function category(Request $request, string $slug): Response
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        return $this->listing($request, ['kind' => 'category', ...$category->only(['name', 'slug', 'description'])]);
    }

    public function industry(Request $request, string $slug): Response
    {
        $industry = Industry::active()->where('slug', $slug)->firstOrFail();

        return $this->listing($request, ['kind' => 'industry', ...$industry->only(['name', 'slug', 'description'])]);
    }

    /** @param array{kind: string, name: string, slug: string, description: ?string}|null $context */
    private function listing(Request $request, ?array $context = null): Response
    {
        $filters = $this->query->filters($request);
        if ($context) {
            $filters[$context['kind']] = $context['slug'];
        }
        $records = $this->query->browse($filters)->paginate(12)->appends($filters)->through(fn ($p) => $this->public->card($p));
        $title = $context ? ($context['kind'] === 'industry' ? 'Software for '.$context['name'].' Businesses' : $context['name'].' Software') : 'Browse business software';

        return $this->page('Index', ['records' => $records, 'filters' => $filters, 'context' => $context, ...$this->query->facets()], $title,
            $context['description'] ?? 'Find software for your business. Search categories, industries, features and pricing.', $request->url());
    }

    public function show(Request $request, string $slug): Response
    {
        $product = $this->query->cards(true)->where('products.slug', $slug)->firstOrFail();
        $product->load(['owner:id,name', 'features', 'offers' => fn ($q) => $q->where('is_active', true),
            'technologies' => fn ($q) => $q->where('is_active', true)->select('technologies.id', 'name', 'slug'),
            'screenshots' => fn ($q) => $this->query->publicMedia($q->getQuery()),
            'releases' => fn ($q) => $this->query->publicRelease($q->getQuery())->select(['id', 'product_id', 'version', 'changelog', 'release_notes', 'released_at'])->reorder()->orderByDesc('released_at')->orderByDesc('id')->limit(1)]);
        $data = $this->public->detail($product);
        $related = $this->query->cards()->where('products.id', '!=', $product->id)
            ->where(fn ($q) => $q->where('category_id', $product->category_id)->orWhereHas('industries', fn ($i) => $i->whereIn('industries.id', $product->industries->modelKeys())))
            ->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$product->category_id])->orderByDesc('published_at')->orderBy('products.id')
            ->limit(4)->get()->map(fn ($p) => $this->public->card($p));

        $sort = $request->validate(['review_sort' => ['nullable', 'in:newest,highest_rating,lowest_rating']])['review_sort'] ?? 'newest';
        $reviews = app(ProductRatingSummary::class)->publicQuery()->where('product_id', $product->id)->with(['customer:id,name', 'purchase:id,version', 'response.author:id,name', 'product:id,origin']);
        if ($sort !== 'newest') {
            $reviews->orderBy('rating', $sort === 'highest_rating' ? 'desc' : 'asc');
        }
        $reviews->orderByDesc('published_at')->orderByDesc('id');
        $reviewData = ['summary' => app(ProductRatingSummary::class)->forProducts([$product->id])[$product->id], 'sort' => $sort, 'records' => $reviews->paginate(10, ['*'], 'reviews_page')->withQueryString()->through(fn ($r) => app(ReviewPresentation::class)->row($r)), 'entry' => app(ReviewPresentation::class)->entry($request->user(), $product)];

        return $this->page('Show', ['product' => $data, 'related' => $related, 'reviews' => $reviewData, 'support_entry' => ['url' => $request->user() && app(SupportEligibility::class)->query($request->user())->where('product_id', $product->id)->exists() ? route('account.support.create', ['product' => $product->slug]) : null]], $data['meta_title'] ?: $product->name,
            $data['meta_description'] ?: $product->short_description, route('marketplace.products.show', $slug));
    }

    /** @param array<string, mixed> $props */
    private function page(string $page, array $props, string $title, string $description, string $canonical): Response
    {
        $seo = compact('title', 'description', 'canonical');

        if ($page === 'Show') {
            $product = $props['product'];
            $seo['structuredData'] = json_encode([
                '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
                'name' => $product['name'], 'description' => $product['short_description'],
                'applicationCategory' => 'BusinessApplication', 'softwareVersion' => $product['release']['version'],
                'url' => $canonical, 'image' => $product['cover']['url'],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        }

        return Inertia::render('marketplace/'.$page, [...$props, 'seo' => $seo])->withViewData(['storefrontSeo' => $seo]);
    }
}
