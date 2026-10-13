<?php

namespace App\Services\Payouts;

use App\Enums\PayoutMethodType;
use App\Models\AuthorPayoutMethod;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayoutMethods
{
    /** @param array<string,mixed> $input */
    public function save(User $actor, array $input, ?AuthorPayoutMethod $target = null): AuthorPayoutMethod
    {
        abort_unless($actor->hasRole('author'), 403);
        if ($target) {
            abort_unless($target->author_user_id === $actor->id, 404);
        }
        foreach (['country_code', 'currency', 'routing_code', 'iban', 'swift_bic', 'account_number'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = strtoupper(preg_replace('/\s+/', '', trim($input[$key])));
            }
        }
        $data = Validator::make($input, ['display_label' => ['required', 'string', 'max:100'], 'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP'])], 'country_code' => ['required', Rule::in(Countries::codes())], 'account_holder_name' => ['required', 'string', 'max:150'], 'bank_name' => ['required', 'string', 'max:150'], 'account_number' => ['nullable', 'regex:/^[A-Z0-9]{4,34}$/'], 'iban' => ['nullable', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'], 'routing_code' => ['nullable', 'string', 'max:40'], 'swift_bic' => ['nullable', 'regex:/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/'], 'is_default' => ['required', 'boolean']])->after(function ($v) use ($input) {
            if (empty($input['account_number']) && empty($input['iban'])) {
                $v->errors()->add('account_number', 'Provide an account number or IBAN.');
            }if (($input['country_code'] ?? null) === 'IN') {
                if (! is_string($input['routing_code'] ?? null) || ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $input['routing_code'])) {
                    $v->errors()->add('routing_code', 'Supply an IFSC in the expected 11-character format.');
                }if (empty($input['account_number'])) {
                    $v->errors()->add('account_number', 'An account number is required for India.');
                }
            } elseif (empty($input['routing_code']) && empty($input['swift_bic'])) {
                $v->errors()->add('routing_code', 'Provide a routing/bank code or SWIFT/BIC for manual transfer instructions.');
            }
        })->validate();
        try {
            return DB::transaction(function () use ($actor, $target, $data) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $method = $target ? AuthorPayoutMethod::whereKey($target->id)->lockForUpdate()->firstOrFail() : new AuthorPayoutMethod;
                if ($target && $method->getAttribute('currency') !== $data['currency']) {
                    throw ValidationException::withMessages(['currency' => 'Create a new method to use a different currency.']);
                }
                $details = array_intersect_key($data, array_flip(['account_holder_name', 'bank_name', 'account_number', 'routing_code', 'iban', 'swift_bic', 'country_code', 'currency']));
                $key = config('payouts.identity_key');
                if (! is_string($key) || strlen($key) < 32) {
                    throw new \LogicException('Stable payout identity key required.');
                }$identity = [PayoutMethodType::BankTransfer->value, $data['currency'], $data['country_code'], $details['account_number'] ?? null, $details['iban'] ?? null, $details['routing_code'] ?? null, $details['swift_bic'] ?? null];
                $fingerprint = hash_hmac('sha256', 'templaara:payout-destination:'.json_encode($identity, JSON_THROW_ON_ERROR), $key);
                if (AuthorPayoutMethod::where('author_user_id', $actor->id)->where('details_fingerprint', $fingerprint)->when($target, fn ($q) => $q->whereKeyNot($target->id))->exists()) {
                    throw ValidationException::withMessages(['account_number' => 'This payout destination is already saved.']);
                }
                if ($data['is_default']) {
                    AuthorPayoutMethod::where('author_user_id', $actor->id)->where('currency', $data['currency'])->where('is_default', true)->get()->each(fn ($m) => $m->forceFill(['is_default' => false])->save());
                }
                $account = $details['account_number'] ?? $details['iban'];
                $attributes = ['display_label' => $data['display_label'], 'bank_name' => $data['bank_name'], 'country_code' => $data['country_code'], 'masked_account' => '•••• '.substr($account, -4), 'details_encrypted' => $details, 'details_fingerprint' => $fingerprint, 'is_default' => $data['is_default'], 'status' => 'active', 'disabled_at' => null];
                if (! $target) {
                    $attributes = [...$attributes, 'public_uuid' => (string) Str::uuid(), 'author_user_id' => $actor->id, 'author_profile_id' => $actor->authorProfile?->id, 'currency' => $data['currency'], 'type' => PayoutMethodType::BankTransfer->value];
                }$method->forceFill($attributes)->save();

                return $method;
            }, 3);
        } catch (QueryException $e) {
            Log::error('Payout method save requires retry.', ['author_id' => $actor->id, 'exception' => $e::class]);
            throw ValidationException::withMessages(['payout' => 'Unable to save payout instructions. Retry or contact Templaara.']);
        }
    }

    public function disable(User $actor, AuthorPayoutMethod $method): void
    {
        abort_unless($actor->hasRole('author'), 403);
        abort_unless($method->author_user_id === $actor->id, 404);
        DB::transaction(function () use ($actor, $method) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $m = AuthorPayoutMethod::whereKey($method->id)->lockForUpdate()->firstOrFail();
            $m->forceFill(['status' => 'disabled', 'is_default' => false, 'disabled_at' => now()])->save();
        });
    }

    /** @return array<string,mixed> */
    public function display(AuthorPayoutMethod $m): array
    {
        return ['uuid' => $m->getAttribute('public_uuid'), 'type' => $m->getAttribute('type'), 'currency' => $m->getAttribute('currency'), 'country_code' => $m->getAttribute('country_code'), 'label' => $m->getAttribute('display_label'), 'bank_name' => $m->getAttribute('bank_name'), 'masked_account' => $m->getAttribute('masked_account'), 'status' => $m->getAttribute('status'), 'is_default' => $m->getAttribute('is_default')];
    }
}
