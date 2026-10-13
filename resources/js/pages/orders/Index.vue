<script setup lang="ts">
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/marketplace';
import * as adminRoutes from '@/routes/admin/orders';
import * as customerRoutes from '@/routes/commerce/orders';
import type { OrderSummary } from '@/types/commerce';
const props = defineProps<{
    admin: boolean;
    filters: Record<string, string>;
    records: {
        data: OrderSummary[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const page = usePage();
const busy = ref(false);
const filters = reactive({
    search: '',
    status: '',
    payment_status: '',
    currency: '',
    from: '',
    to: '',
    ...props.filters,
});
const routes = props.admin ? adminRoutes : customerRoutes;
function apply() {
    router.get(routes.index.url(), filters, {
        preserveState: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}
</script>
<template>
    <Head :title="admin ? 'Orders' : 'My Orders'" />
    <div
        role="main"
        class="space-y-6 p-4 sm:p-6"
        :class="{ 'customer-workspace': !admin }"
    >
        <PageHeader
            :title="admin ? 'Orders' : 'My Orders'"
            eyebrow="YOUR WORKSPACE"
            description="Your orders, payment status and retained purchase details."
        />
        <div
            v-if="Object.keys(page.props.errors).length"
            role="alert"
            class="text-sm text-destructive"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <FilterToolbar
            :compact="!admin"
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
            ><form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="apply"
            >
                <div>
                    <label for="search" class="mb-2 block text-sm">Search</label
                    ><Input
                        id="search"
                        v-model="filters.search"
                        placeholder="Order number or billing name"
                    />
                </div>
                <div>
                    <label for="status" class="mb-2 block text-sm"
                        >Order status</label
                    ><select
                        id="status"
                        v-model="filters.status"
                        class="rounded-md border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="status in [
                                'pending_payment',
                                'payment_failed',
                                'completed',
                                'cancelled',
                            ]"
                            :key="status"
                            :value="status"
                        >
                            {{ status.replaceAll('_', ' ') }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="payment_status" class="mb-2 block text-sm"
                        >Payment status</label
                    ><select
                        id="payment_status"
                        v-model="filters.payment_status"
                        class="rounded-md border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="status in [
                                'pending',
                                'paid',
                                'failed',
                                'cancelled',
                            ]"
                            :key="status"
                        >
                            {{ status }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="currency" class="mb-2 block text-sm"
                        >Currency</label
                    ><select
                        id="currency"
                        v-model="filters.currency"
                        class="rounded-md border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="currency in ['INR', 'USD', 'EUR', 'GBP']"
                            :key="currency"
                        >
                            {{ currency }}
                        </option>
                    </select>
                </div>
                <DateRangeFilter
                    v-model:from="filters.from"
                    v-model:until="filters.to"
                    until-label="To"
                />
                <div class="workspace-filter-actions">
                    <Button type="submit" :disabled="busy">{{
                        busy ? 'Loading…' : 'Apply filters'
                    }}</Button
                    ><Link :href="routes.index()" class="p-2 text-sm underline"
                        >Clear</Link
                    >
                </div>
            </form></FilterToolbar
        >
        <div
            v-if="records.data.length"
            class="overflow-x-auto rounded-lg border"
        >
            <table class="w-full text-left text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="p-4">Order</th>
                        <th v-if="admin" class="p-4">Customer</th>
                        <th class="p-4">Date</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Payment</th>
                        <th class="p-4">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="order in records.data"
                        :key="order.order_number"
                        class="border-t"
                    >
                        <td class="p-4">
                            <Link
                                :href="routes.show(order.order_number)"
                                class="underline"
                                >{{ order.order_number }}</Link
                            >
                        </td>
                        <td v-if="admin" class="p-4">
                            {{ order.customer_name }}
                        </td>
                        <td class="p-4 whitespace-nowrap">
                            {{
                                new Date(order.placed_at).toLocaleDateString(
                                    undefined,
                                    { dateStyle: 'medium' },
                                )
                            }}
                        </td>
                        <td class="p-4 capitalize">
                            <StatusBadge :status="order.status" />
                        </td>
                        <td class="p-4 capitalize">
                            {{ order.payment_status }}
                        </td>
                        <td class="p-4 whitespace-nowrap">
                            {{ formatPrice(order.grand_total, order.currency) }}
                            {{ order.currency }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            title="No orders found"
            description="Your orders will appear here. Try clearing filters to see all your retained purchases."
            ><Link href="/products" class="workspace-empty-action"
                >Browse Software →</Link
            ></EmptyState
        >
        <nav
            v-if="records.last_page > 1"
            aria-label="Orders pagination"
            class="flex justify-between"
        >
            <Link
                v-if="records.prev_page_url"
                :href="records.prev_page_url"
                class="underline"
                >Previous</Link
            ><span
                >Page {{ records.current_page }} of
                {{ records.last_page }}</span
            ><Link
                v-if="records.next_page_url"
                :href="records.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
