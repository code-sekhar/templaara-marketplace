<script setup lang="ts">
import EmptyState from '@/components/design/EmptyState.vue';
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import * as customer from '@/routes/account/support';
import * as author from '@/routes/author/support';
import * as admin from '@/routes/admin/support';
import type { SupportPage, SupportTicket, SupportMode } from '@/types/support';
const props = defineProps<{
    records: SupportPage<SupportTicket>;
    filters: Record<string, string>;
    mode: SupportMode;
    metrics: {
        open: number;
        awaiting_seller: number;
        awaiting_customer: number;
    };
}>();
const routes =
    props.mode === 'admin'
        ? admin
        : props.mode === 'author'
          ? author
          : customer;
const filters = reactive({
    search: '',
    status: '',
    category: '',
    priority: '',
    product: '',
    customer: '',
    seller: '',
    from: '',
    until: '',
    unread: '',
    escalated: '',
    ...props.filters,
});
function apply() {
    router.get(routes.index.url(), filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head title="Product Support" />
    <main
        class="space-y-6 p-4 sm:p-6"
        :class="{ 'customer-workspace': mode === 'customer' }"
    >
        <PageHeader
            :title="
                mode === 'customer'
                    ? 'My Support'
                    : mode === 'author'
                      ? 'Customer Support'
                      : 'Support Tickets'
            "
            eyebrow="YOUR WORKSPACE"
            description="Your purchased software support conversations and updates."
        >
            <Link
                v-if="mode === 'customer'"
                :href="customer.create()"
                class="workspace-empty-action"
                >Open Support Ticket</Link
            >
        </PageHeader>
        <div v-if="mode === 'author'" class="grid gap-3 sm:grid-cols-3">
            <div class="rounded border p-4">
                Open tickets: {{ metrics.open }}
            </div>
            <div class="rounded border p-4">
                Awaiting seller: {{ metrics.awaiting_seller }}
            </div>
            <div class="rounded border p-4">
                Awaiting customer: {{ metrics.awaiting_customer }}
            </div>
        </div>
        <FilterToolbar
            :compact="mode === 'customer'"
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
            ><form class="flex flex-wrap gap-3" @submit.prevent="apply">
                <label class="workspace-filter-label"
                    ><span>Ticket or subject</span
                    ><input
                        v-model="filters.search"
                        aria-label="Ticket or subject"
                        placeholder="Ticket or subject"
                        class="max-w-full rounded border p-3" /></label
                ><label class="workspace-filter-label"
                    ><span>Product slug</span
                    ><input
                        v-model="filters.product"
                        aria-label="Product slug"
                        placeholder="Product slug"
                        class="max-w-full rounded border p-3" /></label
                ><label class="workspace-filter-label"
                    ><span>Status</span
                    ><select
                        v-model="filters.status"
                        aria-label="Status"
                        class="rounded border p-3"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="s in [
                                'awaiting_seller',
                                'awaiting_customer',
                                'resolved',
                                'closed',
                            ]"
                            :key="s"
                            :value="s"
                        >
                            {{ s.replaceAll('_', ' ') }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Priority</span
                    ><select
                        v-model="filters.priority"
                        aria-label="Priority"
                        class="rounded border p-3"
                    >
                        <option value="">All priorities</option>
                        <option v-for="s in ['low', 'normal', 'high']" :key="s">
                            {{ s }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Category</span
                    ><select
                        v-model="filters.category"
                        aria-label="Category"
                        class="rounded border p-3"
                    >
                        <option value="">All categories</option>
                        <option
                            v-for="s in [
                                'installation',
                                'configuration',
                                'bug',
                                'usage_question',
                                'compatibility',
                                'license_activation',
                                'update',
                                'other',
                            ]"
                            :key="s"
                        >
                            {{ s }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Unread</span
                    ><select
                        v-model="filters.unread"
                        aria-label="Unread"
                        class="rounded border p-3"
                    >
                        <option value="">All tickets</option>
                        <option value="1">
                            {{
                                mode === 'admin'
                                    ? 'Customer messages'
                                    : 'Unread messages'
                            }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Escalation</span
                    ><select
                        v-model="filters.escalated"
                        aria-label="Escalation"
                        class="rounded border p-3"
                    >
                        <option value="">All escalations</option>
                        <option value="1">Escalated</option>
                    </select></label
                ><template v-if="mode === 'admin'"
                    ><label class="workspace-filter-label"
                        ><span>Customer name</span
                        ><input
                            v-model="filters.customer"
                            aria-label="Customer name"
                            placeholder="Customer name"
                            class="max-w-full rounded border p-3" /></label
                    ><label class="workspace-filter-label"
                        ><span>Seller name</span
                        ><input
                            v-model="filters.seller"
                            aria-label="Seller name"
                            placeholder="Seller name"
                            class="max-w-full rounded border p-3" /></label></template
                ><DateRangeFilter
                    v-model:from="filters.from"
                    v-model:until="filters.until"
                />
                <div class="workspace-filter-actions">
                    <button type="submit" class="workspace-apply">Filter</button
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
        <EmptyState
            v-if="!records.data.length"
            title="No support tickets match"
            description="Try adjusting your filters, or return to the full list."
            ><button
                type="button"
                class="text-sm font-semibold text-primary"
                @click="router.get($page.url.split('?')[0])"
            >
                Clear filters →
            </button></EmptyState
        >
        <article
            v-for="t in records.data"
            :key="t.uuid"
            class="workspace-record space-y-3 rounded-xl border p-5"
        >
            <p class="text-sm break-words">
                {{ t.ticket_number }} · {{ t.product_name }} · {{ t.version }}
            </p>
            <Link
                :href="routes.show(t.uuid)"
                class="block text-xl font-semibold break-words underline"
                >{{ t.subject }}</Link
            >
            <p class="capitalize">
                <StatusBadge :status="t.status" /> · {{ t.priority
                }}<span v-if="t.is_escalated"> · Escalated</span>
            </p>
            <p>
                {{ mode === 'author' ? t.customer : t.seller
                }}<span v-if="t.unread">
                    · {{ t.unread }}
                    {{
                        mode === 'admin' ? 'customer messages' : 'unread'
                    }}</span
                >
            </p>
            <p class="text-sm">
                Last message {{ new Date(t.last_message_at).toLocaleString() }}
            </p>
        </article>
        <nav class="flex gap-5">
            <Link v-if="records.prev_page_url" :href="records.prev_page_url"
                >Previous tickets</Link
            ><Link v-if="records.next_page_url" :href="records.next_page_url"
                >More tickets</Link
            >
        </nav>
    </main>
</template>
