<?php

namespace App\Services\Reviews;

use App\Events\Reviews\ProductReviewCreated;
use App\Events\Reviews\ProductReviewHidden;
use App\Events\Reviews\ProductReviewResponseCreated;
use App\Events\Reviews\ProductReviewResponseUpdated;
use App\Events\Reviews\ProductReviewRestored;
use App\Events\Reviews\ProductReviewUpdated;
use App\Events\Reviews\ProductReviewWithdrawn;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductReviewEvent;
use App\Models\ProductReviewResponse;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewWorkflow
{
    public function __construct(private ReviewEligibility $eligibility) {}

    /** @param array<string,mixed> $input */
    public function create(User $actor, Product $product, array $input): ProductReview
    {
        $data = $this->content($input);

        return $this->atomic(function () use ($actor, $product, $data) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (ProductReview::where('customer_user_id', $actor->id)->where('product_id', $product->id)->exists()) {
                $this->error('review', 'You already have a review for this software. Edit or restore your existing review.');
            }
            $purchase = $this->eligibility->purchase($actor, $product);
            if (! $purchase) {
                $this->error('review', 'A qualifying verified paid purchase is required to review this software.');
            }
            $r = (new ProductReview)->forceFill([...$data, 'public_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'customer_user_id' => $actor->id, 'verified_order_item_id' => $purchase->id, 'is_verified' => true, 'status' => 'published', 'published_at' => now(), 'event_sequence' => 1]);
            $r->save();
            $this->audit($r, $actor, 'created');
            ProductReviewCreated::dispatch($r->id);

            return $r;
        });
    }

    /** @param array<string,mixed> $input */
    public function action(User $actor, ProductReview $target, string $action, array $input = []): ProductReview
    {
        return $this->atomic(function () use ($actor, $target, $action, $input) {
            User::whereKey($target->getAttribute('customer_user_id'))->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($target->getAttribute('product_id'))->lockForUpdate()->firstOrFail();
            $r = ProductReview::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (in_array($action, ['edit', 'withdraw', 'restore'])) {
                abort_unless($r->getAttribute('customer_user_id') === $actor->id, 404);
                Gate::forUser($actor)->authorize('update', $r);
            } elseif (in_array($action, ['hide', 'unhide'])) {
                Gate::forUser($actor)->authorize('moderate', $r);
            } elseif (in_array($action, ['respond', 'edit_response'])) {
                Gate::forUser($actor)->authorize('respond', $r);
            } else {
                $this->error('review', 'Unknown review action.');
            }
            if (! in_array($action, ['edit', 'restore'])) {
                $this->reconcile($r->getAttribute('customer_user_id'), $product->id);
                $r->refresh();
            }
            $restoreAllowed = (bool) $r->getAttribute('withdrawn_at') || ! $r->getAttribute('is_verified');
            if (in_array($action, ['edit', 'restore'])) {
                $purchase = $this->eligibility->purchase($actor, $product);
                if (! $purchase) {
                    $this->error('review', 'This review cannot be edited or restored without a qualifying purchase.');
                }
                $restoreAllowed = $restoreAllowed || $r->getAttribute('verified_order_item_id') !== $purchase->id;
                if (! $r->getAttribute('is_verified') || $r->getAttribute('verified_order_item_id') !== $purchase->id) {
                    $this->record($r, ['is_verified' => true, 'verified_order_item_id' => $purchase->id], $actor, 'verification_restored');
                }
            }
            if ($action === 'edit') {
                if ($r->getAttribute('withdrawn_at')) {
                    $this->error('review', 'Restore your withdrawn review before editing.');
                }
                $this->record($r, [...$this->content($input), 'edited_at' => now()], $actor, 'edited');
                ProductReviewUpdated::dispatch($r->id);
            } elseif ($action === 'withdraw') {
                if ($r->getAttribute('withdrawn_at')) {
                    $this->error('review', 'This review is already withdrawn.');
                }
                $this->record($r, ['withdrawn_at' => now()], $actor, 'withdrawn');
                ProductReviewWithdrawn::dispatch($r->id);
            } elseif ($action === 'restore') {
                if (! $restoreAllowed) {
                    $this->error('review', 'This review is already restored.');
                }
                $this->record($r, ['withdrawn_at' => null], $actor, 'restored_by_customer');
                ProductReviewRestored::dispatch($r->id);
            } elseif ($action === 'hide') {
                $reason = Validator::make(['reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null)], ['reason' => ['required', 'string', 'min:5', 'max:2000']])->validate()['reason'];
                if ($r->status->value === 'hidden') {
                    $this->error('review', 'This review is already hidden.');
                }
                $this->record($r, ['status' => 'hidden', 'hidden_at' => now()], $actor, 'hidden_by_admin', $reason);
                ProductReviewHidden::dispatch($r->id);
            } elseif ($action === 'unhide') {
                if ($r->status->value !== 'hidden') {
                    $this->error('review', 'This review is not hidden.');
                }
                $this->record($r, ['status' => 'published', 'hidden_at' => null], $actor, 'restored_by_admin');
                ProductReviewRestored::dispatch($r->id);
            } else {
                if (! app(ProductRatingSummary::class)->publicQuery()->whereKey($r->id)->exists()) {
                    $this->error('review', 'Responses require a currently public verified review.');
                }
                $body = $this->text($input, 2, 3000);
                $response = $r->response()->lockForUpdate()->first();
                if ($action === 'respond') {
                    if ($response) {
                        $this->error('response', 'This review already has a response.');
                    }
                    $response = (new ProductReviewResponse)->forceFill(['public_uuid' => (string) Str::uuid(), 'review_id' => $r->id, 'author_user_id' => $actor->id, 'body' => $body, 'published_at' => now()]);
                } else {
                    abort_unless($response && $response->getAttribute('author_user_id') === $actor->id, 404);
                    $response->forceFill(['body' => $body, 'edited_at' => now()]);
                }
                $response->forceFill(['review_sequence' => $r->getAttribute('event_sequence') + 1])->save();
                $this->record($r, [], $actor, $action === 'respond' ? 'response_created' : 'response_edited');
                ($action === 'respond' ? ProductReviewResponseCreated::class : ProductReviewResponseUpdated::class)::dispatch($r->id);
            }

            return $r;
        });
    }

    /** Called within the refund transaction, after all selected item claims change. */
    public function reconcile(int $customer, int $product): void
    {
        $r = ProductReview::where('customer_user_id', $customer)->where('product_id', $product)->lockForUpdate()->first();
        if (! $r || ! $r->getAttribute('is_verified')) {
            return;
        }
        $p = $this->eligibility->purchase(User::findOrFail($customer), Product::findOrFail($product));
        if ($p && $r->getAttribute('verified_order_item_id') !== $p->id) {
            $this->record($r, ['verified_order_item_id' => $p->id, 'is_verified' => true], null, 'verification_restored', 'Verification rebound to another qualifying purchase.');
        } elseif (! $p && $r->getAttribute('is_verified')) {
            $this->record($r, ['is_verified' => false], null, 'verification_lost', 'No qualifying purchase remains.');
        }
    }

    /** @param array<string,mixed> $fields */
    private function record(ProductReview $r, array $fields, ?User $actor, string $type, ?string $reason = null): void
    {
        $r->forceFill([...$fields, 'event_sequence' => $r->getAttribute('event_sequence') + 1])->save();
        $this->audit($r, $actor, $type, $reason);
    }

    private function audit(ProductReview $r, ?User $actor, string $type, ?string $reason = null): void
    {
        (new ProductReviewEvent)->forceFill(['review_id' => $r->id, 'actor_id' => $actor?->id, 'sequence' => $r->getAttribute('event_sequence'), 'type' => $type, 'reason' => $reason, 'occurred_at' => now()])->save();
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function content(array $input): array
    {
        $body = $this->text($input, 20, 5000);

        return Validator::make(['rating' => $input['rating'] ?? null, 'title' => is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null), 'body' => $body], ['rating' => ['required', 'integer', 'between:1,5'], 'title' => ['bail', 'nullable', 'string', 'max:120', function ($a, $v, $fail) {
            if (strip_tags($v) !== $v) {
                $fail('Use plain text without HTML.');
            }
        }], 'body' => ['required', 'string']])->validate();
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, int $min, int $max): string
    {
        return Validator::make(['body' => is_string($input['body'] ?? null) ? trim($input['body']) : ($input['body'] ?? null)], ['body' => ['bail', 'required', 'string', 'min:'.$min, 'max:'.$max, function ($a, $v, $fail) {
            if (strip_tags($v) !== $v) {
                $fail('Use plain text without HTML.');
            }
        }]])->validate()['body'];
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param callable():ProductReview $work */
    private function atomic(callable $work): ProductReview
    {
        try {
            return DB::transaction(fn () => $work(), 3);
        } catch (QueryException $e) {
            $this->error('review', 'The review changed or could not be saved safely. Refresh and retry.');
        }
    }
}
