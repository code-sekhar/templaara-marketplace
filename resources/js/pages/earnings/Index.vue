<script setup lang="ts">
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Balances from '@/components/earnings/Balances.vue';
import { formatPrice } from '@/lib/marketplace';
import * as payoutRoutes from '@/routes/author/payouts';
import * as authorRoutes from '@/routes/author/earnings';
import * as adminRoutes from '@/routes/admin/earnings';
import type { Balance, Earning, Page } from '@/types/earnings';
const props = defineProps<{
    admin: boolean;
    balances: Balance[];
    earnings: Page<Earning>;
    filters: { search?: string; currency?: string; status?: string };
}>();
const filters = reactive({
    search: props.filters.search ?? '',
    currency: props.filters.currency ?? '',
    status: props.filters.status ?? '',
});
const routes = props.admin ? adminRoutes : authorRoutes;
function filter() {
    router.get(routes.index.url(), filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head :title="admin ? 'Author Earnings Report' : 'My Earnings'" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <header>
            <PageHeader
                :title="admin ? 'Author Earnings Report' : 'My Earnings'"
                eyebrow="YOUR WORKSPACE"
            />
            <p class="mt-2 text-muted-foreground">
                Verified sales, marketplace commission and your net earnings.
            </p>
        </header>
        <Balances :balances="balances" /><Link
            v-if="!admin"
            :href="payoutRoutes.index()"
            class="inline-block underline"
            >Manage Payouts</Link
        >
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
                    aria-label="Search products"
                    placeholder="Search products"
                    class="rounded-lg border p-2"
                /><select
                    v-model="filters.currency"
                    aria-label="Currency"
                    class="rounded-lg border p-2"
                >
                    <option value="">All currencies</option>
                    <option v-for="c in ['INR', 'USD', 'EUR', 'GBP']" :key="c">
                        {{ c }}
                    </option></select
                ><select
                    v-model="filters.status"
                    aria-label="Status"
                    class="rounded-lg border p-2"
                >
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="available">Available</option></select
                ><button class="rounded-lg border px-4 py-2">
                    Apply filters
                </button>
            </form></FilterToolbar
        >
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="p-3">Software / Order</th>
                        <th v-if="admin" class="p-3">Author</th>
                        <th class="p-3">Gross</th>
                        <th class="p-3">Commission</th>
                        <th class="p-3">Net earning</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Available from</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in earnings.data"
                        :key="e.uuid"
                        class="border-b"
                    >
                        <td class="p-3">
                            <Link
                                :href="routes.show(e.uuid)"
                                class="font-medium underline"
                                >{{ e.product }}</Link
                            >
                            <p class="text-sm">
                                {{ e.order_number }} · {{ e.offer }} · v{{
                                    e.version
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    new Date(e.created_at).toLocaleDateString()
                                }}
                            </p>
                        </td>
                        <td v-if="admin" class="p-3">{{ e.author }}</td>
                        <td class="p-3 whitespace-nowrap">
                            {{ formatPrice(e.gross, e.currency) }}
                        </td>
                        <td class="p-3 whitespace-nowrap">
                            {{ formatPrice(e.commission, e.currency) }} ({{
                                e.rate
                            }}%)
                        </td>
                        <td class="p-3 whitespace-nowrap">
                            {{ formatPrice(e.net, e.currency) }}
                        </td>
                        <td class="p-3 capitalize">
                            <StatusBadge :status="e.status" />
                        </td>
                        <td class="p-3">
                            {{ new Date(e.available_at).toLocaleDateString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!earnings.data.length" class="p-6">
                No earnings match these filters.
            </p>
        </div>
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in earnings.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2"
                    :class="{ 'bg-muted': link.active }"
                    preserve-scroll
                    ><span v-html="link.label" /></Link
                ><span
                    v-else
                    class="px-3 py-2 text-muted-foreground"
                    v-html="link.label"
            /></template>
        </nav>
    </div>
</template>
