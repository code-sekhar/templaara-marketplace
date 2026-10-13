<script setup lang="ts">
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { formatPrice } from '@/lib/marketplace';
import * as customer from '@/routes/account/refunds';
import * as adminRoutes from '@/routes/admin/refunds';
import type { RefundData } from '@/types/refunds';
import type { Page } from '@/types/earnings';
const props = defineProps<{
    admin: boolean;
    refunds: Page<RefundData>;
    filters: Record<string, string>;
    report: {
        currency: string;
        original_gross: string;
        original_commission: string;
        net_sales: string;
        net_commission: string;
        official_refunded: string;
        platform_net: string;
        gross_refunded: string;
        commission_reversed: string;
        author_adjustment: string;
    }[];
}>();
const routes = props.admin ? adminRoutes : customer;
const filter = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    currency: props.filters.currency ?? '',
    reason: props.filters.reason ?? '',
    from: props.filters.from ?? '',
    until: props.filters.until ?? '',
});
function submit() {
    router.get(routes.index.url(), filter, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head :title="admin ? 'Refund Queue' : 'My Refunds'" />
    <div
        role="main"
        class="space-y-6 p-4 sm:p-6"
        :class="{ 'customer-workspace': !admin }"
    >
        <PageHeader
            :title="admin ? 'Refund Queue' : 'My Refunds'"
            eyebrow="YOUR WORKSPACE"
            description="Track your refund requests and confirmed external refunds."
        />
        <p>
            Manual refunds. Completed means an administrator confirmed an
            external refund and recorded its reference.
        </p>
        <div v-if="admin" class="flex flex-wrap gap-4">
            <article
                v-for="r in report"
                :key="r.currency"
                class="rounded border p-4"
            >
                <h2>{{ r.currency }} financial summary</h2>
                <p>
                    Original sales:
                    {{ formatPrice(r.original_gross, r.currency) }} · Original
                    commission:
                    {{ formatPrice(r.original_commission, r.currency) }}
                </p>
                <p>
                    Net sales: {{ formatPrice(r.net_sales, r.currency) }} · Net
                    commission: {{ formatPrice(r.net_commission, r.currency) }}
                </p>
                <p>
                    Official-product refunds:
                    {{ formatPrice(r.official_refunded, r.currency) }} ·
                    Platform net: {{ formatPrice(r.platform_net, r.currency) }}
                </p>
                <p>
                    Gross refunded:
                    {{ formatPrice(r.gross_refunded, r.currency) }}
                </p>
                <p>
                    Commission reversed:
                    {{ formatPrice(r.commission_reversed, r.currency) }}
                </p>
                <p>
                    Author adjustment:
                    {{ formatPrice(r.author_adjustment, r.currency) }}
                </p>
            </article>
        </div>
        <FilterToolbar
            :compact="!admin"
            :filters="filter"
            @clear="router.get($page.url.split('?')[0])"
            @remove="
                router.get(
                    $page.url.split('?')[0],
                    Object.fromEntries(
                        Object.entries(filter).filter(
                            ([key]) => key !== $event,
                        ),
                    ),
                )
            "
            ><form class="flex flex-wrap gap-3" @submit.prevent="submit">
                <label class="workspace-filter-label"
                    ><span>Reference, order or customer</span
                    ><input
                        v-model="filter.search"
                        aria-label="Reference, order or customer"
                        placeholder="Reference, order or customer"
                        class="rounded border p-2" /></label
                ><label class="workspace-filter-label"
                    ><span>Status</span
                    ><select
                        v-model="filter.status"
                        aria-label="Status"
                        class="rounded border p-2"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="s in [
                                'requested',
                                'approved',
                                'processing',
                                'completed',
                                'failed',
                                'cancelled',
                            ]"
                            :key="s"
                        >
                            {{ s }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Currency</span
                    ><select
                        v-model="filter.currency"
                        aria-label="Currency"
                        class="rounded border p-2"
                    >
                        <option value="">All currencies</option>
                        <option
                            v-for="c in ['INR', 'USD', 'EUR', 'GBP']"
                            :key="c"
                        >
                            {{ c }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Reason</span
                    ><select
                        v-model="filter.reason"
                        aria-label="Reason"
                        class="rounded border p-2"
                    >
                        <option value="">All reasons</option>
                        <option
                            v-for="r in [
                                'duplicate_purchase',
                                'product_not_as_described',
                                'technical_issue',
                                'customer_request',
                                'fraud_or_risk',
                                'other',
                            ]"
                            :key="r"
                        >
                            {{ r }}
                        </option>
                    </select></label
                ><DateRangeFilter
                    v-model:from="filter.from"
                    v-model:until="filter.until"
                />
                <div class="workspace-filter-actions">
                    <button type="submit" class="workspace-apply">
                        Apply filters</button
                    ><button
                        type="button"
                        class="workspace-clear"
                        @click="router.get($page.url.split('?')[0])"
                    >
                        Clear filters
                    </button>
                </div>
            </form></FilterToolbar
        >
        <div
            v-if="refunds.data.length"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th class="p-3">Reference / Order</th>
                        <th v-if="admin" class="p-3">Customer</th>
                        <th class="p-3">Amount</th>
                        <th class="p-3">Status / Reason</th>
                        <th class="p-3">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in refunds.data"
                        :key="r.uuid"
                        class="border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="routes.show(r.uuid)"
                                class="underline"
                                >{{ r.reference }}</Link
                            >
                            <p>{{ r.order }}</p>
                        </td>
                        <td v-if="admin" class="p-3">{{ r.customer }}</td>
                        <td class="p-3 whitespace-nowrap">
                            {{ formatPrice(r.amount, r.currency) }}
                        </td>
                        <td class="p-3">
                            <StatusBadge :status="r.status" />
                            <p>{{ r.reason_code.replaceAll('_', ' ') }}</p>
                        </td>
                        <td class="p-3">
                            {{ new Date(r.requested_at).toLocaleDateString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            title="No refunds match"
            description="Your refund requests appear here. Completed means an administrator confirmed an external refund and recorded its reference."
            ><button
                type="button"
                class="workspace-empty-action"
                @click="router.get($page.url.split('?')[0])"
            >
                Clear filters →
            </button></EmptyState
        >
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(l, i) in refunds.links" :key="i"
                ><Link v-if="l.url" :href="l.url" class="rounded border p-2"
                    ><span v-html="l.label" /></Link
                ><span v-else class="p-2" v-html="l.label"
            /></template>
        </nav>
    </div>
</template>
