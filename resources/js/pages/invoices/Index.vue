<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/design/PageHeader.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/marketplace';
import type { DocumentSummary } from '@/lib/invoicing';
const props = defineProps<{
    admin: boolean;
    filters: Record<string, string>;
    records: {
        data: DocumentSummary[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const filters = reactive({
    search: props.filters.search ?? '',
    currency: props.filters.currency ?? '',
    kind: props.filters.kind ?? '',
    tax_type: props.filters.tax_type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
function apply() {
    router.get(props.admin ? '/admin/invoices' : '/account/invoices', filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head title="Invoices" />
    <main
        class="space-y-6 p-4 md:p-8"
        :class="{ 'customer-workspace': !admin }"
    >
        <PageHeader
            :title="admin ? 'Invoice inspection' : 'My invoices'"
            description="Immutable purchase invoices and completed-refund credit notes."
            eyebrow="Purchase documents"
        />
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
        >
            <form class="flex flex-wrap gap-3" @submit.prevent="apply">
                <label class="workspace-filter-label"
                    ><span>Invoice or order number</span
                    ><Input
                        v-model="filters.search"
                        aria-label="Invoice or order number"
                        placeholder="Invoice or order number"
                        class="max-w-xs" /></label
                ><label class="workspace-filter-label"
                    ><span>Currency</span
                    ><select
                        v-model="filters.currency"
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
                    ><span>Document type</span
                    ><select
                        v-model="filters.kind"
                        aria-label="Document type"
                        class="rounded border p-2"
                    >
                        <option value="">All documents</option>
                        <option value="invoice">Invoices</option>
                        <option value="credit_note">Credit notes</option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Tax type</span
                    ><select
                        v-model="filters.tax_type"
                        aria-label="Tax type"
                        class="rounded border p-2"
                    >
                        <option value="">All tax types</option>
                        <option
                            v-for="t in [
                                'NONE',
                                'CGST_SGST',
                                'IGST',
                                'VAT',
                                'SALES_TAX',
                                'GST',
                                'OTHER',
                            ]"
                            :key="t"
                        >
                            {{ t }}
                        </option>
                    </select></label
                ><DateRangeFilter
                    v-model:from="filters.from"
                    v-model:until="filters.to"
                    until-label="To"
                />
                <div class="workspace-filter-actions">
                    <Button type="submit">Filter</Button
                    ><button
                        type="button"
                        class="workspace-clear"
                        @click="router.get($page.url.split('?')[0])"
                    >
                        Clear filters
                    </button>
                </div>
            </form>
        </FilterToolbar>
        <EmptyState
            v-if="!records.data.length"
            title="No invoices to show"
            description="Issued purchase documents will appear here. Adjust your filters if you're looking for a specific invoice."
        />
        <article
            v-for="doc in records.data"
            :key="doc.public_uuid"
            class="workspace-record elevated-card flex flex-wrap items-center justify-between gap-4 p-5"
        >
            <div>
                <Link :href="doc.url" class="font-semibold underline">{{
                    doc.number
                }}</Link>
                <p
                    class="mt-1 font-mono text-xs break-all text-muted-foreground"
                >
                    {{ doc.order_number }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    <span class="font-medium capitalize">{{
                        doc.kind.replaceAll('_', ' ')
                    }}</span>
                    ·
                    <time :datetime="doc.issued_at" :title="doc.issued_at">{{
                        new Date(doc.issued_at).toLocaleString('en-GB', {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                        })
                    }}</time>
                </p>
            </div>
            <div>
                <p>
                    {{ formatPrice(doc.grand_total, doc.currency) }}
                    {{ doc.currency }}
                </p>
                <a :href="doc.pdf_url" class="text-sm underline"
                    >Download PDF</a
                >
            </div>
        </article>
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, index) in records.links" :key="index"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2"
                    :aria-current="link.active ? 'page' : undefined"
                    ><span v-html="link.label" /></Link
                ><span
                    v-else
                    class="px-3 py-2 text-muted-foreground"
                    v-html="link.label"
            /></template>
        </nav>
    </main>
</template>
