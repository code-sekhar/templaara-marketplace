<script setup lang="ts">
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Balances from '@/components/earnings/Balances.vue';
import { formatPrice } from '@/lib/marketplace';
import * as authorRoutes from '@/routes/author/payouts';
import * as adminRoutes from '@/routes/admin/payouts';
import * as methods from '@/routes/author/payout-methods';
import type { Page, Balance } from '@/types/earnings';
import type { Payout } from '@/types/payouts';
const props = defineProps<{
    admin: boolean;
    balances: Balance[];
    payouts: Page<Payout>;
    filters: {
        status?: string;
        currency?: string;
        search?: string;
        author?: string;
        from?: string;
        until?: string;
    };
    report: {
        currency: string;
        status: string;
        amount: string;
        count: number;
    }[];
}>();
const routes = props.admin ? adminRoutes : authorRoutes;
const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    currency: props.filters.currency ?? '',
    author: props.filters.author ?? '',
    from: props.filters.from ?? '',
    until: props.filters.until ?? '',
});
function filter() {
    router.get(routes.index.url(), filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head :title="admin ? 'Payout Queue' : 'My Payouts'" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <PageHeader
            :title="admin ? 'Payout Queue' : 'My Payouts'"
            eyebrow="YOUR WORKSPACE"
        />
        <p>
            Manual payout processing. Requests reserve funds immediately; paid
            status records an administrator-confirmed external transfer.
        </p>
        <Balances :balances="balances" />
        <nav v-if="!admin" class="flex gap-4">
            <Link :href="authorRoutes.create()" class="rounded border p-3"
                >Request Payout</Link
            ><Link :href="methods.index()" class="rounded border p-3"
                >Payout Methods</Link
            >
        </nav>
        <div v-if="admin && report.length" class="flex flex-wrap gap-3">
            <p
                v-for="r in report"
                :key="r.currency + r.status"
                class="rounded border p-3"
            >
                {{ r.currency }} · <StatusBadge :status="r.status" />:
                {{ formatPrice(r.amount, r.currency) }} ({{ r.count }})
            </p>
        </div>
        <FilterToolbar
            :filters="filters"
            @clear="router.get($page.url.split('?')[0])"
            @remove="
                router.get(
                    $page.url.split('?')[0],
                    Object.fromEntries(
                        Object.entries(filters).filter(
                            ([key]) => key !== $event,
                        ),
                    ),
                )
            "
            ><form class="flex flex-wrap gap-3" @submit.prevent="filter">
                <input
                    v-model="filters.search"
                    aria-label="Payout reference"
                    placeholder="Payout reference"
                    class="rounded border p-2"
                /><input
                    v-if="admin"
                    v-model="filters.author"
                    aria-label="Author name"
                    placeholder="Author name"
                    class="rounded border p-2"
                /><select
                    v-model="filters.status"
                    aria-label="Status"
                    class="rounded border p-2"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="s in [
                            'requested',
                            'approved',
                            'processing',
                            'paid',
                            'failed',
                            'cancelled',
                        ]"
                        :key="s"
                    >
                        {{ s }}
                    </option></select
                ><select
                    v-model="filters.currency"
                    aria-label="Currency"
                    class="rounded border p-2"
                >
                    <option value="">All currencies</option>
                    <option v-for="c in ['INR', 'USD', 'EUR', 'GBP']" :key="c">
                        {{ c }}
                    </option></select
                ><DateRangeFilter
                    v-model:from="filters.from"
                    v-model:until="filters.until"
                /><button class="rounded border p-3">Apply filters</button>
            </form></FilterToolbar
        >
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th class="p-3">Reference / Date</th>
                        <th v-if="admin" class="p-3">Author</th>
                        <th class="p-3">Amount</th>
                        <th class="p-3">Method</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="p in payouts.data"
                        :key="p.uuid"
                        class="border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="routes.show(p.uuid)"
                                class="underline"
                                >{{ p.reference }}</Link
                            >
                            <p class="text-sm">
                                {{
                                    new Date(
                                        p.requested_at,
                                    ).toLocaleDateString()
                                }}
                            </p>
                        </td>
                        <td v-if="admin" class="p-3">{{ p.author }}</td>
                        <td class="p-3 whitespace-nowrap">
                            {{ formatPrice(p.amount, p.currency) }}
                        </td>
                        <td class="p-3">
                            {{ p.method.label }} · {{ p.method.masked_account }}
                        </td>
                        <td class="p-3 capitalize">
                            <StatusBadge :status="p.status" />
                            <p v-if="p.paid_at" class="text-sm">
                                {{ new Date(p.paid_at).toLocaleDateString() }}
                            </p>
                            <p v-if="p.failure_reason" class="text-sm">
                                {{ p.failure_reason }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!payouts.data.length" class="p-5">
                No payouts match these filters.
            </p>
        </div>
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in payouts.links" :key="i"
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
