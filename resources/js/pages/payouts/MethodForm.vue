<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import * as routes from '@/routes/author/payout-methods';
import type { PayoutMethod } from '@/types/payouts';
const props = defineProps<{ method: PayoutMethod | null }>();
const form = useForm({
    display_label: props.method?.label ?? '',
    currency: props.method?.currency ?? 'INR',
    country_code: props.method?.country_code ?? 'IN',
    account_holder_name: '',
    bank_name: props.method?.bank_name ?? '',
    account_number: '',
    iban: '',
    routing_code: '',
    swift_bic: '',
    is_default: props.method?.is_default ?? true,
});
const fields = [
    ['display_label', 'Display label'],
    ['country_code', 'Country code (ISO, e.g. IN, US, GB)'],
    ['account_holder_name', 'Account holder name'],
    ['bank_name', 'Bank name'],
    ['account_number', 'Account number'],
    ['iban', 'IBAN (optional)'],
    ['routing_code', 'Routing / bank code (IFSC for India)'],
    ['swift_bic', 'SWIFT / BIC (optional)'],
] as const;
function submit() {
    const options = {
        onSuccess: () =>
            form.reset(
                'account_holder_name',
                'account_number',
                'iban',
                'routing_code',
                'swift_bic',
            ),
    };
    if (props.method) form.put(routes.update.url(props.method.uuid), options);
    else form.post(routes.store.url(), options);
}
</script>
<template>
    <Head :title="method ? 'Edit Payout Method' : 'Add Payout Method'" />
    <div role="main" class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
        <Link :href="routes.index()" class="underline">Back to methods</Link>
        <h1 class="text-3xl font-semibold">
            {{ method ? 'Edit Payout Method' : 'Add Payout Method' }}
        </h1>
        <p>
            Manual bank transfer instructions. Enter the full destination
            details; saved account numbers are masked. Updates apply to future
            requests only. Saving makes this method active.
        </p>
        <p v-if="method">Current destination: {{ method.masked_account }}</p>
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <label v-for="[key, label] in fields" :key="key" class="block"
                >{{ label
                }}<input
                    :id="key"
                    v-model="form[key]"
                    :required="
                        [
                            'display_label',
                            'country_code',
                            'account_holder_name',
                            'bank_name',
                        ].includes(key)
                    "
                    autocomplete="off"
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors[key]" /></label
            ><label
                >Currency<select
                    id="currency"
                    v-model="form.currency"
                    :disabled="!!method"
                    class="mt-1 block w-full rounded border p-2"
                >
                    <option v-for="c in ['INR', 'USD', 'EUR', 'GBP']" :key="c">
                        {{ c }}
                    </option></select
                ><InputError :message="form.errors.currency" /></label
            ><label class="flex items-center gap-2"
                ><input v-model="form.is_default" type="checkbox" />Default for
                this currency</label
            >
            <p class="text-sm text-muted-foreground sm:col-span-2">
                India requires an account number and IFSC format. Other
                countries need an account number or IBAN, plus a routing/bank
                code or SWIFT/BIC. Format checks do not verify bank ownership or
                transfer eligibility.
            </p>
            <InputError
                :message="(form.errors as Record<string, string>).payout"
            /><button
                :disabled="form.processing"
                class="rounded border p-3 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Save payout method' }}
            </button>
        </form>
    </div>
</template>
