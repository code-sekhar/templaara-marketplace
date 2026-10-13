<?php

namespace App\Models;

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property SupportStatus $status
 * @property SupportCategory $category
 * @property SupportPriority $priority
 * @property User|null $seller
 */
class SupportTicket extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => SupportStatus::class, 'category' => SupportCategory::class, 'priority' => SupportPriority::class, 'is_escalated' => 'boolean', 'admin_locked' => 'boolean', 'support_ends_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'last_message_at' => 'immutable_datetime', 'customer_last_read_at' => 'immutable_datetime', 'seller_last_read_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'reopened_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Support history is retained.'));
        static::updating(function (self $r): void {
            if (array_diff(array_keys($r->getDirty()), ['status', 'priority', 'last_message_at', 'customer_last_read_at', 'seller_last_read_at', 'customer_last_read_sequence', 'seller_last_read_sequence', 'resolved_at', 'closed_at', 'reopened_at', 'is_escalated', 'admin_locked', 'event_sequence', 'updated_at'])) {
                throw new \LogicException('Support history identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<User,$this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<User,$this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    /** @return BelongsTo<Product,$this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<OrderItem,$this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return HasMany<SupportMessage,$this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    /** @return HasMany<SupportTicketEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id');
    }
}
