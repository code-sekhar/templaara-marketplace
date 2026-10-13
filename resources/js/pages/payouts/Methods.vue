<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import * as routes from '@/routes/author/payout-methods';
import * as payouts from '@/routes/author/payouts';
import type { PayoutMethod } from '@/types/payouts';
import type { Page } from '@/types/earnings';
defineProps<{ methods: Page<PayoutMethod> }>();
function disable(uuid: string) {
    if (
        window.confirm(
            'Disable this payout method? Existing requests keep their original destination.',
        )
    )
        router.post(routes.disable.url(uuid));
}
</script>
<template>
    <Head title="Payout Methods" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <h1 class="text-3xl font-semibold">Payout Methods</h1>
        <p>
            Bank transfer instructions for manual processing by Templaara.
            Saving a method does not verify a bank account.
        </p>
        <nav class="flex gap-4">
            <Link :href="routes.create()" class="rounded border p-3"
                >Add Payout Method</Link
            ><Link :href="payouts.index()" class="rounded border p-3"
                >Payouts</Link
            >
        </nav>
        <div class="grid gap-4 md:grid-cols-2">
            <article
                v-for="m in methods.data"
                :key="m.uuid"
                class="space-y-3 rounded-xl border p-5"
            >
                <h2 class="font-semibold">{{ m.label }}</h2>
                <p>
                    Bank Transfer · {{ m.bank_name }} · {{ m.masked_account }}
                </p>
                <p>
                    {{ m.currency }} · {{ m.country_code }} ·
                    <StatusBadge :status="m.status" />
                    {{ m.is_default ? '· Default' : '' }}
                </p>
                <div class="flex gap-4">
                    <Link :href="routes.edit(m.uuid)" class="underline"
                        >Edit instructions</Link
                    ><button
                        v-if="m.status === 'active'"
                        class="underline"
                        @click="disable(m.uuid)"
                    >
                        Disable
                    </button>
                </div>
            </article>
        </div>
        <p v-if="!methods.data.length" class="rounded border p-5">
            No payout methods yet.
        </p>
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in methods.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2"
                    ><span v-html="link.label" /></Link
                ><span v-else class="px-3 py-2" v-html="link.label"
            /></template>
        </nav>
    </div>
</template>
