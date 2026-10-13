<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorPortfolioItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_featured' => 'boolean', 'revision' => 'integer', 'completion_year' => 'integer', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /** @return BelongsTo<AuthorProfile,$this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AuthorProfile::class, 'author_profile_id');
    }

    /** @return BelongsToMany<Technology,$this> */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'author_portfolio_technology')->withTimestamps();
    }

    /** @return HasMany<AuthorProfileMedia,$this> */
    public function media(): HasMany
    {
        return $this->hasMany(AuthorProfileMedia::class)->where('is_current', true);
    }
}
