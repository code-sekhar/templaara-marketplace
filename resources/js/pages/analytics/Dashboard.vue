<script setup lang="ts">
import DashboardIntro from '@/components/design/DashboardIntro.vue';
import MetricCard from '@/components/design/MetricCard.vue';
import {
    Package,
    UsersRound,
    Star,
    Banknote,
    Wallet,
    RotateCcw,
    ShoppingBag,
} from '@lucide/vue';
import { formatPrice } from '@/lib/marketplace';
import { Head, Link } from '@inertiajs/vue3';
import * as routes from '@/routes/admin/analytics';
import FiltersForm from '@/components/analytics/Filters.vue';
import ReportTable from '@/components/analytics/ReportTable.vue';
import AnalyticsCharts from '@/components/analytics/Charts.vue';
import { useAuthorization } from '@/composables/useAuthorization';
import { computed } from 'vue';
import type { Row, Analytics, Filters } from '@/types/analytics';
const props = defineProps<{
    analytics: Analytics;
    filters: Filters;
    timezone: string;
    facets: Record<string, { id: number; name: string }[]>;
}>();
const { can } = useAuthorization();
const labels: Record<string, string> = {
    gross: 'Gross sales',
    refunded: 'Sales-cohort refunds',
    retained: 'Retained gross',
    commission: 'Author-sale commission',
    author_earned: 'Author earnings before reversals',
    commission_reversed: 'Commission reversals',
    author_reversed: 'Author reversals',
    net_commission: 'Retained commission',
    official_retained: 'Official software retained gross',
    platform_retained: 'Platform retained gross + commission',
    net_author_earned: 'Net author earned',
    completed_refunds_in_period: 'Refunds completed in period',
    paid_payouts: 'Paid payouts in period',
    outstanding: 'Outstanding author position at end',
    paid_orders: 'Paid orders',
    paid_items: 'Paid items',
    units: 'Paid units',
    purchasers: 'Purchasers',
    aov: 'Average order value',
    item_refund_rate: 'Item refund rate (%)',
    order_affected_rate: 'Affected-order rate (%)',
};
const friendly = (s: string) => s.replaceAll('_', ' ');
const rankings = computed<
    { title: string; rows: Row[]; columns: Record<string, string> }[]
>((): { title: string; rows: Row[]; columns: Record<string, string> }[] => [
    {
        title: 'Products by retained gross (compare within currency)',
        rows: props.analytics.topProducts,
        columns: {
            product: 'Product',
            currency: 'Currency',
            retained: 'Retained gross',
        },
    },
    {
        title: 'Products by paid units',
        rows: props.analytics.topUnits,
        columns: {
            product: 'Product',
            currency: 'Currency',
            units: 'Units',
        },
    },
    {
        title: 'Products by public-qualified rating',
        rows: props.analytics.topRated,
        columns: {
            product: 'Product',
            average: 'Rating',
            count: 'Reviews',
        },
    },
    {
        title: 'Authors by retained sales (compare within currency)',
        rows: props.analytics.topAuthors,
        columns: {
            author: 'Author',
            currency: 'Currency',
            retained: 'Retained gross',
            net_earned: 'Net earned',
        },
    },
    {
        title: 'Authors by net earned (compare within currency)',
        rows: props.analytics.topAuthorEarnings,
        columns: {
            author: 'Author',
            currency: 'Currency',
            net_earned: 'Net earned',
        },
    },
    {
        title: 'Category sales',
        rows: props.analytics.categoryPerformance,
        columns: {
            name: 'Category',
            currency: 'Currency',
            retained: 'Retained gross',
        },
    },
    {
        title: 'Industry sales (overlapping attribution)',
        rows: props.analytics.industryPerformance,
        columns: {
            name: 'Industry',
            currency: 'Currency',
            retained: 'Retained gross',
        },
    },
]);
</script>
<template>
    <Head title="Marketplace analytics" />
    <main class="dashboard-refined space-y-6 p-4 md:p-8">
        <DashboardIntro
            title="Marketplace intelligence"
            eyebrow="ADMIN OVERVIEW"
            description="A clear view of your marketplace, grounded in authoritative commercial records."
            ><Link
                :href="routes.index()"
                class="rounded-lg bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground"
                >Reports &amp; exports →</Link
            ></DashboardIntro
        >
        <p class="text-sm text-muted-foreground">
            {{ filters.from }} through {{ filters.to }} · {{ timezone }}.
            Current inventory and operational queues are live; outstanding
            positions are through period end. No currency conversion.
        </p>
        <nav aria-label="Dashboard sections" class="flex flex-wrap gap-2">
            <a
                v-for="section in [
                    { label: 'Overview', id: 'marketplace-overview' },
                    { label: 'Charts', id: 'marketplace-charts' },
                    { label: 'Operations', id: 'marketplace-operations' },
                ]"
                :key="section.id"
                :href="`#${section.id}`"
                class="rounded-lg border bg-white px-3 py-2 text-xs font-medium text-primary hover:bg-brand-blue-soft focus-visible:ring-2 focus-visible:ring-ring"
                >{{ section.label }}</a
            >
        </nav>
        <FiltersForm
            :filters="filters"
            :facets="facets"
            :url="routes.dashboard().url"
        />
        <section
            id="marketplace-overview"
            aria-label="Marketplace summary"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <MetricCard
                label="Public-visible products"
                :value="analytics.products.live"
                description="Current central visibility qualification"
                :icon="Package"
            /><MetricCard
                label="Approved authors"
                :value="analytics.authors.approved"
                description="Current author application approval"
                :icon="UsersRound"
                accent="purple"
            /><MetricCard
                label="Purchasing customers"
                :value="analytics.customers.purchasing"
                description="Verified paid purchasers in this period"
                :icon="ShoppingBag"
                accent="coral"
            /><MetricCard
                label="Qualified reviews"
                :value="analytics.reviews.count"
                description="Public-qualified review records"
                :icon="Star"
                accent="emerald"
            />
        </section>
        <section
            v-for="f in analytics.financial"
            :key="f.currency"
            class="space-y-4"
        >
            <div class="flex items-center gap-3">
                <span
                    class="rounded-lg border bg-white px-3 py-1.5 text-sm font-semibold"
                    >{{ f.currency }}</span
                >
                <h2 class="text-sm font-medium text-muted-foreground">
                    Marketplace economics · no currency conversion
                </h2>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Gross sales"
                    :value="formatPrice(f.gross, f.currency)"
                    description="Immutable verified paid purchase snapshots"
                    :icon="Banknote"
                /><MetricCard
                    label="Retained gross"
                    :value="formatPrice(String(f.retained), f.currency)"
                    description="Selected sales cohort less completed refunds"
                    :icon="Wallet"
                    accent="emerald"
                /><MetricCard
                    label="Refund activity"
                    :value="
                        formatPrice(
                            String(f.completed_refunds_in_period),
                            f.currency,
                        )
                    "
                    description="Refunds completed during this period"
                    :icon="RotateCcw"
                    accent="coral"
                /><MetricCard
                    label="Paid payouts"
                    :value="formatPrice(String(f.paid_payouts), f.currency)"
                    description="Only payouts marked paid in this period"
                    :icon="Wallet"
                    accent="purple"
                />
            </div>
        </section>
        <div id="marketplace-charts" class="scroll-mt-24">
            <AnalyticsCharts :charts="analytics.charts" />
        </div>
        <details class="elevated-card space-y-4 p-6">
            <summary class="cursor-pointer text-sm font-semibold text-primary">
                Financial detail &amp; metric definitions
            </summary>
            <h2 class="text-lg font-semibold">
                Sales &amp; marketplace economics
            </h2>
            <p
                v-if="!analytics.financial.length"
                class="rounded-lg border p-8 text-muted-foreground"
            >
                No commercial activity yet. No revenue or growth to report.
            </p>
            <div class="grid gap-4 xl:grid-cols-2">
                <article
                    v-for="f in analytics.financial"
                    :key="f.currency"
                    class="rounded-lg border p-5"
                >
                    <h3 class="text-xl font-semibold">{{ f.currency }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-3">
                        <template v-for="(label, key) in labels" :key="key"
                            ><dt class="text-sm text-muted-foreground">
                                {{ label }}
                            </dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ f[key] ?? '—' }}
                            </dd></template
                        >
                    </dl>
                    <p class="mt-4 text-sm">
                        Gross change vs preceding equal period:
                        {{ f.comparison.absolute }} ·
                        {{
                            f.comparison.new
                                ? 'New'
                                : f.comparison.percentage === null
                                  ? 'No percentage comparison'
                                  : `${f.comparison.percentage}%`
                        }}
                    </p>
                </article>
            </div>
            <p class="text-xs text-muted-foreground">
                Gross uses immutable verified paid item snapshots. Cohort
                refunds include completed refunds through period end for those
                purchases. Pending claims do not reduce sales. Outstanding = all
                earned − completed author reversals − paid payouts; reserves
                remain obligations.
            </p>
        </details>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="(group, title) in {
                    Customers: analytics.customers,
                    Authors: analytics.authors,
                    Refunds: analytics.refunds,
                }"
                :key="title"
                class="rounded-lg border p-4"
            >
                <h2 class="font-semibold">{{ title }}</h2>
                <dl class="mt-3 space-y-2">
                    <div
                        v-for="(value, key) in group"
                        :key="key"
                        class="flex justify-between gap-3 text-sm"
                    >
                        <dt class="text-muted-foreground capitalize">
                            {{ friendly(key) }}
                        </dt>
                        <dd>{{ value }}</dd>
                    </div>
                </dl>
            </article>
            <article class="rounded-lg border p-4">
                <h2 class="font-semibold">Public-qualified reviews</h2>
                <p class="mt-3">
                    {{ analytics.reviews.count }} reviews ·
                    {{ analytics.reviews.average ?? 'No rating yet' }}
                </p>
                <p class="text-sm">
                    {{ analytics.reviews.products }} products ·
                    {{ analytics.reviews.unanswered }} unanswered
                </p>
                <p
                    v-for="(count, i) in analytics.reviews.distribution"
                    :key="i"
                    class="text-sm"
                >
                    {{ i + 1 }} stars: {{ count }}
                </p>
            </article>
        </section>
        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-lg border p-4">
                <h2 class="font-semibold">Live software</h2>
                <p class="my-3">
                    {{ analytics.products.live }} public-visible ·
                    {{ analytics.products.author }} author ·
                    {{ analytics.products.official }} official
                </p>
                <p>
                    {{ analytics.products.changes_requested }} changes requested
                </p>
                <h3 class="mt-3 font-medium">By category</h3>
                <p
                    v-for="r in analytics.products.categories"
                    :key="r.name"
                    class="text-sm"
                >
                    {{ r.name }}: {{ r.count }}
                </p>
                <h3 class="mt-3 font-medium">By industry (overlaps)</h3>
                <p
                    v-for="r in analytics.products.industries"
                    :key="r.name"
                    class="text-sm"
                >
                    {{ r.name }}: {{ r.count }}
                </p>
            </article>
            <article class="rounded-lg border p-4">
                <h2 class="font-semibold">Support workload</h2>
                <template v-for="(value, key) in analytics.support" :key="key"
                    ><p
                        v-if="typeof value === 'number'"
                        class="mt-2 text-sm capitalize"
                    >
                        {{ friendly(key) }}: {{ value }}
                    </p></template
                >
                <h3 class="mt-3 font-medium">Priority distribution</h3>
                <p
                    v-for="(r, i) in analytics.support.priorities as Record<
                        string,
                        string | number
                    >[]"
                    :key="i"
                >
                    {{ r.priority }}: {{ r.count }}
                </p>
                <h3 class="mt-3 font-medium">Category distribution</h3>
                <p
                    v-for="(r, i) in analytics.support.categories as Record<
                        string,
                        string | number
                    >[]"
                    :key="i"
                >
                    {{ r.category }}: {{ r.count }}
                </p>
            </article>
        </section>
        <section id="marketplace-operations" class="scroll-mt-24">
            <h2 class="mb-3 text-lg font-semibold">
                Operational queues · current
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="q in analytics.queues"
                    :key="q.label"
                    class="rounded-lg border p-4"
                >
                    <Link
                        v-if="can(q.permission)"
                        :href="q.url"
                        class="underline"
                        >{{ q.label }}</Link
                    ><span v-else>{{ q.label }}</span>
                    <p class="mt-2 text-xl">{{ q.count }}</p>
                </div>
            </div>
        </section>
        <details
            v-for="ranking in rankings"
            :key="ranking.title"
            class="rounded-xl border bg-white p-4"
        >
            <summary
                class="cursor-pointer text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ ranking.title
                }}<span class="ml-2 text-xs font-normal text-muted-foreground"
                    >{{ ranking.rows.length }} rows</span
                >
            </summary>
            <div class="mt-4">
                <ReportTable :columns="ranking.columns" :rows="ranking.rows" />
            </div>
        </details>
    </main>
</template>
