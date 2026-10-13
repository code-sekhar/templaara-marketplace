<?php

namespace App\Services\Authors;

use App\Models\AuthorProfile;
use App\Models\User;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Marketplace\PublicProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class PublicAuthor
{
    /** @return Builder<AuthorProfile> */
    public function visible(): Builder
    {
        return AuthorProfile::where('status', 'approved')->whereHas('user.roles', fn ($q) => $q->where('name', 'author')->where('guard_name', 'web'));
    }

    /** @return array<string,mixed>|null */
    public function card(?User $owner): ?array
    {
        $p = $owner?->authorProfile;
        if (! $p || $p->status->value !== 'approved' || ! $owner->hasRole('author')) {
            return null;
        }
        $p->loadMissing(['media', 'technologies']);

        return ['name' => $p->display_name ?: $owner->name, 'slug' => $p->slug, 'url' => route('marketplace.authors.show', $p->slug, false),
            'verified' => ! $p->is_legacy && $p->applications()->where('status', 'approved')->exists(),
            'headline' => $p->professional_headline, 'years_of_experience' => $p->years_of_experience,
            'avatar' => app(AuthorMedia::class)->present($p->media->firstWhere('purpose', 'avatar')),
            'technologies' => $p->technologies->where('is_active', true)->take(5)->map->only(['name', 'slug'])->values()->all()];
    }

    /** @return array<string,mixed> */
    public function page(AuthorProfile $p): array
    {
        $p->load(['user', 'media', 'technologies', 'expertise', 'portfolio' => fn ($q) => $q->where('is_public', true)->limit(config('author_professional.portfolio_max')), 'portfolio.technologies', 'portfolio.media']);
        $products = app(MarketplaceQuery::class)->cards()->where('owner_id', $p->user_id)->orderByDesc('published_at')->orderByDesc('products.id')->paginate(12)->withQueryString()->through(fn ($product) => app(PublicProduct::class)->card($product));
        $portfolio = $p->portfolio->map(function ($item): array {
            $row = app(AuthorPortfolio::class)->present($item);
            unset($row['revision'],$row['is_public'],$row['sort_order']);
            $row['technologies'] = $item->technologies->where('is_active', true)->map->only(['name', 'slug'])->values()->all();

            return $row;
        })->all();

        return ['author' => [...$this->card($p->user), 'bio' => $p->bio, 'author_type' => $p->author_type?->value,
            'country_code' => $p->public_location ? $p->country_code : null, 'state_region' => $p->public_location ? $p->state_region : null, 'city' => $p->public_location ? $p->city : null,
            'languages' => $p->languages ?? [], 'support_availability' => $p->support_availability, 'founded_year' => $p->founded_year, 'team_size' => $p->team_size,
            'expertise' => $p->expertise->where('is_active', true)->map->only(['name', 'slug'])->values()->all(),
            'links' => array_filter($p->only(['website_url', 'github_url', 'linkedin_url', 'portfolio_url']), fn ($url) => $this->safeLink($url)),
            'cover' => app(AuthorMedia::class)->present($p->media->firstWhere('purpose', 'cover')), 'portfolio' => $portfolio,
            'published_products' => $products->total()],
            'products' => $products,
            'seo' => ['title' => ($p->display_name ?: 'Software author').' | Templaara', 'description' => mb_substr(strip_tags($p->professional_headline ?: $p->bio ?: 'Business software by a Templaara author.'), 0, 160), 'canonical' => route('marketplace.authors.show', $p->slug)]];
    }

    private function safeLink(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        return ! Validator::make(['url' => $url], ['url' => ['url:https', app(ProfessionalProfileInput::class)->link()]])->fails();
    }
}
