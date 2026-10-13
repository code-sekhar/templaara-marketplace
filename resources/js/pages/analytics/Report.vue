<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import * as routes from '@/routes/admin/analytics';
import FiltersForm from '@/components/analytics/Filters.vue';
import ReportTable from '@/components/analytics/ReportTable.vue';
import type { Filters, Row } from '@/types/analytics';
defineProps<{
    report: string;
    filters: Filters;
    timezone: string;
    facets: Record<string, { id: number; name: string }[]>;
    statuses: string[];
    columns: Record<string, string>;
    rows: {
        data: Row[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const pageLabel = (label: string) =>
    label.replace('&laquo;', '‹').replace('&raquo;', '›');
</script>
<template>
    <Head :title="`${report} report`" />
    <main class="space-y-6 p-4 md:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold capitalize">
                {{ report }} report
            </h1>
            <Link :href="routes.index()" class="underline">All reports</Link>
        </div>
        <p v-if="report === 'tax'" class="text-sm text-muted-foreground">
            Reporting timezone: {{ timezone }}. Invoice and credit-note issue
            dates define this period. Tax credits copy original purchased tax,
            independently of revenue and earnings reports. Currencies remain
            separate.
        </p>
        <p v-else class="text-sm text-muted-foreground">
            Reporting timezone: {{ timezone }}. Selected sales and their
            completed refunds through period end. Refund and payout report dates
            refer to requests; dashboard paid payouts and refund activity use
            settlement dates. Product and author financial performance reflects
            paid sales. Customer rows represent purchasers in the period.
        </p>
        <FiltersForm
            :filters="filters"
            :facets="facets"
            :url="routes.show(report).url"
            :statuses="statuses"
        /><a
            :href="
                routes.exportMethod(report, {
                    query: { ...filters, page: undefined },
                }).url
            "
            class="inline-block rounded-md border px-4 py-2 text-sm"
            >Export CSV</a
        >
        <p class="text-sm">{{ rows.total }} rows</p>
        <ReportTable :columns="columns" :rows="rows.data" />
        <nav aria-label="Report pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in rows.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2 text-sm"
                    :class="
                        link.active ? 'bg-primary text-primary-foreground' : ''
                    "
                    :aria-current="link.active ? 'page' : undefined"
                    >{{ pageLabel(link.label) }}</Link
                ><span v-else class="px-3 py-2 text-sm text-muted-foreground">{{
                    pageLabel(link.label)
                }}</span></template
            >
        </nav>
    </main>
</template>
