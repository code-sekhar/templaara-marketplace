<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\AuthorApplication;
use App\Models\AuthorApplicationEvent;
use App\Models\AuthorPayoutMethod;
use App\Models\AuthorProfile;
use App\Models\CommissionPolicy;
use App\Models\MarketplaceSetting;
use App\Models\MarketplaceSettingEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutEvent;
use App\Models\ProductLifecycleEvent;
use App\Models\ProductRelease;
use App\Models\ProductReview;
use App\Models\ProductReviewEvent;
use App\Models\ProductReviewResponse;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (MarketplaceSettingEvent::where('actor_user_id', $this->user()->id)->exists() || MarketplaceSetting::where('updated_by_user_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained marketplace configuration history and cannot be deleted.');

                return;
            }
            if (SupportTicket::where('customer_user_id', $this->user()->id)->orWhere('seller_user_id', $this->user()->id)->exists() || SupportMessage::where('sender_user_id', $this->user()->id)->exists() || SupportTicketEvent::where('actor_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained support history and cannot be deleted.');

                return;
            }
            if (ProductReview::where('customer_user_id', $this->user()->id)->exists() || ProductReviewResponse::where('author_user_id', $this->user()->id)->exists() || ProductReviewEvent::where('actor_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained customer review or moderation history and cannot be deleted.');

                return;
            }
            if (Refund::where('customer_user_id', $this->user()->id)->exists() || RefundEvent::where('actor_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained refund history and cannot be deleted.');

                return;
            }
            if (AuthorPayoutMethod::where('author_user_id', $this->user()->id)->exists() || PayoutEvent::where('actor_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained payout instructions or financial history and cannot be deleted.');

                return;
            }
            if (AuthorProfile::where('user_id', $this->user()->id)->exists() || AuthorApplication::where('reviewed_by', $this->user()->id)->exists() || AuthorApplicationEvent::where('actor_id', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained author onboarding or moderation history and cannot be deleted.');

                return;
            }
            if (Order::where('user_id', $this->user()->id)->exists() || OrderItem::where('author_user_id', $this->user()->id)->exists() || CommissionPolicy::where('created_by', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account has retained commercial orders and cannot be deleted.');

                return;
            }
            if (ProductLifecycleEvent::where('actor_id', $this->user()->id)->exists() || ProductRelease::where('created_by', $this->user()->id)->exists()) {
                $validator->errors()->add('password', 'Your account is referenced by product releases or moderation history and cannot be deleted.');

                return;
            }
            if ($this->user()->products()->exists()) {
                $validator->errors()->add('password', 'Your account owns products and cannot be deleted while those products remain.');
            }
        }];
    }
}
