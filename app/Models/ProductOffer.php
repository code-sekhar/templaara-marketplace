<?php

namespace App\Models;

use App\Enums\LicenseType;
use Database\Factories\ProductOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $price
 * @property LicenseType $license_type
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable(['name', 'description', 'price', 'license_type', 'support_period_days', 'updates_period_days', 'is_default', 'is_active', 'sort_order', 'activation_limit'])]
class ProductOffer extends Model
{
    /** @use HasFactory<ProductOfferFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activation_limit' => 'integer', 'price' => 'decimal:2', 'license_type' => LicenseType::class, 'support_period_days' => 'integer', 'updates_period_days' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
