<?php

namespace App\Models;

use App\Enums\AuthorApplicationStatus;
use App\Enums\AuthorType;
use App\Services\Authors\AuthorProfileInput;
use App\Services\Authors\ProfessionalProfileInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $user_id
 * @property AuthorApplicationStatus $status
 * @property AuthorType|null $author_type
 * @property bool $is_legacy
 */
class AuthorProfile extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => AuthorApplicationStatus::class, 'author_type' => AuthorType::class, 'is_legacy' => 'boolean', 'languages' => 'array', 'public_location' => 'boolean', 'years_of_experience' => 'integer', 'founded_year' => 'integer', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Author profiles are retained.'));
        static::updating(function (self $p): void {
            if ($p->isDirty(['user_id', 'is_legacy']) || (! $p->getOriginal('status')->editable() && $p->getOriginal('status') !== AuthorApplicationStatus::Approved && $p->isDirty([...app(AuthorProfileInput::class)->fields(), ...app(ProfessionalProfileInput::class)->fields()]))) {
                throw new \LogicException('Submitted profile content is frozen.');
            }
            if ($p->getOriginal('status') === AuthorApplicationStatus::Approved && $p->isDirty(['display_name', 'company_name', 'author_type', 'country_code', 'slug', 'support_email', 'support_phone'])) {
                throw new \LogicException('Verified identity changes require review.');
            }
        });
    }

    /** @return BelongsToMany<Technology,$this> */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'author_profile_technology')->withTimestamps();
    }

    /** @return BelongsToMany<Category,$this> */
    public function expertise(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'author_profile_category')->withTimestamps();
    }

    /** @return HasMany<AuthorPortfolioItem,$this> */
    public function portfolio(): HasMany
    {
        return $this->hasMany(AuthorPortfolioItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<AuthorProfileMedia,$this> */
    public function media(): HasMany
    {
        return $this->hasMany(AuthorProfileMedia::class)->where('is_current', true);
    }

    /** @return BelongsTo<User,$this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AuthorApplication,$this> */
    public function applications(): HasMany
    {
        return $this->hasMany(AuthorApplication::class)->orderByDesc('round_number');
    }
}
