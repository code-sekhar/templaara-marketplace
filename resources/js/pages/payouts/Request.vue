<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import Balances from '@/components/earnings/Balances.vue';
import { formatPrice } from '@/lib/marketplace';
import * as routes from '@/routes/author/payouts';
import * as methodsRoutes from '@/routes/author/payout-methods';
import type { Balance } from '@/types/earnings';
import type { PayoutMethod } from '@/types/payouts';
const props = defineProps<{
    balances: Balance[];
    methods: PayoutMethod[];
    minimums: Record<string, string | null>;
}>();
const form = useForm({
    currency: props.balances[0]?.currency ?? 'INR',
    method: '',
    amount: '',
    request_key: crypto.randomUUID(),
});
const availableMethods = computed(() =>
    props.methods.filter((m) => m.currency === form.currency),
);
watch(
    () => form.currency,
    () => {
        form.method = availableMethods.value[0]?.uuid ?? '';
    },
    { immediate: true },
);
function submit() {
    form.post(routes.store.url());
}
</script>
<template>
    <Head title="Request Payout" />
    <div role="main" class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <Link :href="routes.index()" class="underline">Back to payouts</Link>
        <h1 class="text-3xl font-semibold">Request Payout</h1>
        <p>
            Templaara processes approved payouts manually. No automatic transfer
            or processing time is promised.
        </p>
        <Balances :balances="balances" /><Link
            :href="methodsRoutes.create()"
            class="inline-block underline"
            >Add Payout Method</Link
        >
        <form
            class="max-w-xl space-y-4 rounded-xl border p-5"
            @submit.prevent="submit"
        >
            <label class="block"
                >Currency<select
                    id="payout-currency"
                    v-model="form.currency"
                    class="mt-1 block w-full rounded border p-2"
                >
                    <option v-for="c in ['INR', 'USD', 'EUR', 'GBP']" :key="c">
                        {{ c }}
                    </option></select
                ><InputError :message="form.errors.currency" /></label
            ><label class="block"
                >Payout method<select
                    id="payout-method"
                    v-model="form.method"
                    required
                    class="mt-1 block w-full rounded border p-2"
                >
                    <option value="">Choose an active method</option>
                    <option
                        v-for="m in availableMethods"
                        :key="m.uuid"
                        :value="m.uuid"
                    >
                        {{ m.label }} · {{ m.masked_account }}
                    </option></select
                ><InputError :message="form.errors.method" /></label
            ><label class="block"
                >Amount<input
                    id="payout-amount"
                    v-model="form.amount"
                    inputmode="decimal"
                    required
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.amount"
            /></label>
            <p v-if="minimums[form.currency]">
                Minimum:
                {{ formatPrice(minimums[form.currency]!, form.currency) }}
            </p>
            <p v-else>
                This currency needs an approved payout minimum policy before
                requests can be accepted.
            </p>
            <InputError
                :message="(form.errors as Record<string, string>).payout"
            /><InputError :message="form.errors.request_key" /><button
                :disabled="
                    form.processing ||
                    !availableMethods.length ||
                    !minimums[form.currency]
                "
                class="rounded border p-3 disabled:opacity-50"
            >
                {{ form.processing ? 'Reserving…' : 'Request Payout' }}
            </button>
        </form>
    </div>
</template>
