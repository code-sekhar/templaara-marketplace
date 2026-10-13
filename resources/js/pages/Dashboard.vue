<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Library,
    ShoppingBag,
    FileText,
    LifeBuoy,
    Star,
    Bell,
    ArrowUpRight,
    Settings,
    KeyRound,
} from '@lucide/vue';
import DashboardIntro from '@/components/design/DashboardIntro.vue';
import MetricCard from '@/components/design/MetricCard.vue';
import ChartCard from '@/components/design/ChartCard.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/marketplace';
import { useAuthorization } from '@/composables/useAuthorization';
import { dashboard } from '@/routes';
const props = defineProps<{
    workspace: {
        counts: {
            purchases: number;
            active_licenses: number;
            open_tickets: number;
            invoices: number;
        };
        recent_purchases: {
            reference: string;
            currency: string;
            total: string;
            paid_at: string;
            products: string[];
        }[];
        support_activity: {
            reference: string;
            number: string;
            subject: string;
            status: string;
            last_activity_at: string;
        }[];
    };
}>();
const page = usePage();
const { can } = useAuthorization();
const shortcuts = [
    {
        title: 'Software library',
        text: 'Your software and purchase access',
        url: '/account/library',
        icon: Library,
    },
    {
        title: 'Orders & payments',
        text: 'Retained purchase details',
        url: '/account/orders',
        icon: ShoppingBag,
    },
    {
        title: 'Invoices & credit notes',
        text: 'Your private purchase documents',
        url: '/account/invoices',
        icon: FileText,
    },
    {
        title: 'Your reviews',
        text: 'Share your product experience',
        url: '/account/reviews',
        icon: Star,
    },
    {
        title: 'Notifications',
        text: 'Updates from your workspace',
        url: '/notifications',
        icon: Bell,
    },
    {
        title: 'Account settings',
        text: 'Profile, security and preferences',
        url: '/settings/profile',
        icon: Settings,
    },
];
defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});
</script>
<template>
    <Head title="Your workspace" />
    <div role="main" class="dashboard-refined customer-workspace space-y-6">
        <DashboardIntro
            :title="`Welcome back, ${page.props.auth.user.name}`"
            description="Your business software, purchase access and support, thoughtfully organized."
            eyebrow="YOUR WORKSPACE"
            ><Button as-child
                ><Link href="/products"
                    >Browse Software<ArrowUpRight
                        class="size-4"
                        aria-hidden="true" /></Link></Button
        ></DashboardIntro>
        <section
            aria-label="Account summary"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <MetricCard
                label="Paid purchases"
                :value="props.workspace.counts.purchases"
                description="Verified completed orders · all time"
                :icon="ShoppingBag"
                accent="blue"
            /><MetricCard
                label="Active licenses"
                :value="props.workspace.counts.active_licenses"
                description="Current, unexpired license records"
                :icon="KeyRound"
                accent="emerald"
            /><MetricCard
                label="Open support tickets"
                :value="props.workspace.counts.open_tickets"
                description="Awaiting you or the seller"
                :icon="LifeBuoy"
                accent="purple"
            /><MetricCard
                label="Issued invoices"
                :value="props.workspace.counts.invoices"
                description="Available purchase documents"
                :icon="FileText"
                accent="coral"
            />
        </section>
        <div class="grid items-start gap-6 xl:grid-cols-[1.4fr_1fr]">
            <ChartCard
                title="Your recent purchases"
                description="Your latest verified paid orders. Amounts retain their original currency."
                ><template #controls
                    ><Link
                        href="/account/orders"
                        class="text-xs font-semibold text-primary"
                        >All orders →</Link
                    ></template
                >
                <div v-if="workspace.recent_purchases.length" class="divide-y">
                    <Link
                        v-for="order in workspace.recent_purchases"
                        :key="order.reference"
                        :href="`/account/orders/${order.reference}`"
                        class="dashboard-activity-row flex flex-wrap items-center justify-between gap-4 py-4 transition-colors hover:bg-brand-blue-soft/40"
                        ><div class="flex min-w-0 flex-1 gap-3">
                            <span class="icon-tile"
                                ><ShoppingBag class="size-5" aria-hidden="true"
                            /></span>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold">
                                    {{
                                        order.products.join(' · ') ||
                                        'Software purchase'
                                    }}
                                </h3>
                                <p
                                    class="mt-1 text-[11px] break-all text-muted-foreground"
                                >
                                    {{ order.reference }}
                                    <span class="ml-2 whitespace-nowrap"
                                        >Paid
                                        {{
                                            new Date(
                                                order.paid_at,
                                            ).toLocaleDateString('en-GB')
                                        }}</span
                                    >
                                </p>
                            </div>
                        </div>
                        <p class="text-sm font-semibold tabular-nums">
                            {{ formatPrice(order.total, order.currency) }}
                        </p></Link
                    >
                </div>
                <EmptyState
                    v-else
                    title="Your software journey starts here"
                    description="Completed purchases will appear here. Find the tools your business needs."
                    ><Button as-child size="sm"
                        ><Link href="/products">Explore software</Link></Button
                    ></EmptyState
                ></ChartCard
            >
            <ChartCard
                title="Support activity"
                description="Recent activity on your own product-support tickets."
                ><template #controls
                    ><Link
                        href="/account/support"
                        class="text-xs font-semibold text-primary"
                        >All tickets →</Link
                    ></template
                >
                <div v-if="workspace.support_activity.length" class="divide-y">
                    <Link
                        v-for="ticket in workspace.support_activity"
                        :key="ticket.reference"
                        :href="`/account/support/${ticket.reference}`"
                        class="dashboard-activity-row block py-4 transition-colors hover:bg-brand-blue-soft/40"
                        ><div
                            class="mb-3 flex items-center justify-between gap-3"
                        >
                            <LifeBuoy
                                class="size-4 text-review"
                                aria-hidden="true"
                            /><StatusBadge :status="ticket.status" />
                        </div>
                        <h3 class="text-sm font-semibold">
                            {{ ticket.subject }}
                        </h3>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ ticket.number }}
                        </p></Link
                    >
                </div>
                <EmptyState
                    v-else
                    title="A clear support workspace"
                    :icon="LifeBuoy"
                    description="Your eligible product-support conversations will appear here."
                    ><Link
                        href="/account/support"
                        class="text-sm font-semibold text-primary"
                        >Open support →</Link
                    ></EmptyState
                ></ChartCard
            >
        </div>
        <section class="elevated-card p-6">
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-semibold">
                    Everything you need, close at hand
                </h2>
                <span class="hidden text-xs text-muted-foreground sm:block"
                    >Your account essentials</span
                >
            </div>
            <nav
                aria-label="Workspace shortcuts"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
                <Link
                    v-for="item in shortcuts"
                    :key="item.url"
                    :href="item.url"
                    class="group flex items-center gap-3 rounded-lg border border-transparent p-3 transition-colors hover:border-primary/25 hover:bg-brand-blue-soft"
                    ><span class="icon-tile"
                        ><component
                            :is="item.icon"
                            class="size-4"
                            aria-hidden="true"
                    /></span>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-semibold">{{ item.title }}</h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ item.text }}
                        </p>
                    </div>
                    <ArrowUpRight
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                /></Link>
            </nav>
        </section>
        <div
            v-if="page.props.authorNavigation || can('reports.view')"
            class="flex flex-wrap gap-4"
        >
            <Link
                v-if="page.props.authorNavigation"
                :href="page.props.authorNavigation.url"
                class="text-sm font-semibold text-primary"
                >{{ page.props.authorNavigation.label }} →</Link
            ><Link
                v-if="can('reports.view')"
                href="/admin/dashboard"
                class="text-sm font-semibold text-primary"
                >Marketplace analytics →</Link
            >
        </div>
    </div>
</template>
