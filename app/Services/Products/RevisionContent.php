<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\ProductRevision;
use App\Models\Technology;
use Illuminate\Support\Arr;

class RevisionContent
{
    /** @return array<string,mixed> */
    public function fromProduct(Product $p): array
    {
        $p->load(['features', 'offers', 'industries', 'technologies']);

        return [...$p->only($this->fields()), 'features' => $p->features->map(fn ($x) => Arr::except($x->toArray(), ['id', 'product_id', 'created_at', 'updated_at']))->all(), 'offers' => $p->offers->where('is_active', true)->map(fn ($x) => Arr::except($x->toArray(), ['id', 'product_id', 'created_at', 'updated_at']))->values()->all(), 'industry_ids' => $p->industries->modelKeys(), 'technology_ids' => $p->technologies->modelKeys()];
    }

    /** @return list<string> */
    public function fields(): array
    {
        return ['category_id', 'name', 'slug', 'short_description', 'description', 'business_problem', 'target_audience', 'key_benefits', 'currency', 'demo_url', 'demo_username', 'demo_password', 'documentation_url', 'preview_video_url', 'is_active', 'meta_title', 'meta_description'];
    }

    public function projection(ProductRevision $r): Product
    {
        $p = clone $r->product;
        $p->unsetRelations();
        $p->fill(Arr::only($r->content, $this->fields()));
        $p->setRelation('category', Category::findOrFail($r->content['category_id']));
        $p->setRelation('owner', $r->product->owner);
        $p->setRelation('industries', Industry::whereIn('id', $r->content['industry_ids'] ?? [])->get());
        $p->setRelation('technologies', Technology::whereIn('id', $r->content['technology_ids'] ?? [])->get());
        $p->setRelation('features', collect($r->rows('features'))->map(fn ($x) => (new ProductFeature)->forceFill($x)));
        $p->setRelation('offers', collect($r->rows('offers'))->map(fn ($x) => (new ProductOffer)->forceFill($x)));

        return $p;
    }

    public function promote(ProductRevision $r): void
    {
        $p = $r->product;
        $p->fill(Arr::only($r->content, $this->fields()));
        $p->forceFill(['version_label' => $r->release->version])->save();
        $p->industries()->sync($r->content['industry_ids'] ?? []);
        $p->technologies()->sync($r->content['technology_ids'] ?? []);
        $p->features()->delete();
        foreach ($r->content['features'] ?? [] as $x) {
            $p->features()->create(Arr::except($x, ['id']));
        }
        // Retire old commercial identities; restrictive OrderItem FKs remain intact.
        $p->offers()->update(['is_active' => false, 'is_default' => false]);
        foreach ($r->content['offers'] as $x) {
            $p->offers()->create(Arr::except($x, ['id']));
        }
        $p->media()->where('is_current', true)->update(['is_current' => false]);
        $p->media()->whereIn('id', $r->media()->pluck('product_media.id'))->update(['is_current' => true]);
    }
}
