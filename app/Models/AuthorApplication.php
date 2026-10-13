<?php

namespace App\Models;

use App\Enums\AuthorApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $user_id
 * @property int $author_profile_id
 * @property int $round_number
 * @property int|null $reviewed_by
 * @property AuthorApplicationStatus $status
 * @property array<string,mixed> $snapshot
 */
class AuthorApplication extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => AuthorApplicationStatus::class, 'snapshot' => 'array', 'submitted_at' => 'immutable_datetime', 'review_started_at' => 'immutable_datetime', 'decision_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Author application history is retained.'));
        static::updating(function (self $a): void {
            if ($a->getRawOriginal('decision_at') !== null || $a->isDirty(['user_id', 'author_profile_id', 'round_number', 'snapshot', 'snapshot_version', 'submitted_at'])) {
                throw new \LogicException('Application identity, submitted snapshot and closed rounds are immutable.');
            }
            if ($a->getRawOriginal('reviewed_by') !== null && $a->isDirty(['reviewed_by', 'review_started_at'])) {
                throw new \LogicException('Review claims cannot be reassigned.');
            }
        });
    }

    /** @return BelongsTo<AuthorProfile,$this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AuthorProfile::class, 'author_profile_id');
    }

    /** @return BelongsTo<User,$this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User,$this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<AuthorApplicationFeedback,$this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(AuthorApplicationFeedback::class)->orderBy('id');
    }

    /** @return HasMany<AuthorApplicationEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(AuthorApplicationEvent::class)->orderBy('id');
    }
}
