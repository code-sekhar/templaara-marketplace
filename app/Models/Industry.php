<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogScopes;
use Database\Factories\IndustryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['name', 'slug', 'description', 'sort_order', 'is_active', 'icon'])]
class Industry extends Model
{
    /** @use HasFactory<IndustryFactory> */
    use HasCatalogScopes, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }
}
