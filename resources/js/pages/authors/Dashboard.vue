<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Package,
    Send,
    FilePenLine,
    BadgeCheck,
    ArrowUpRight,
    Wallet,
    UserRound,
    LifeBuoy,
} from '@lucide/vue';
import { useAuthorization } from '@/composables/useAuthorization';
import * as products from '@/routes/author/products';
import * as profile from '@/routes/author/profile';
import Balances from '@/components/earnings/Balances.vue';
import type { Balance } from '@/types/earnings';
import MetricCard from '@/components/design/MetricCard.vue';
import ChartCard from '@/components/design/ChartCard.vue';
import DashboardIntro from '@/components/design/DashboardIntro.vue';
import StatusDistribution from '@/components/design/StatusDistribution.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    completeness: { percentage: number; missing: string[] };
    earnings: Balance[];
    counts: Record<string, number>;
    sellerStatus: string;
}>();
const total = computed(() =>
    Object.values(props.counts).reduce((sum, n) => sum + Number(n), 0),
);
const page = usePage();
const { can } = useAuthorization();
</script>
<template>
    <Head title="Author Dashboard" />
    <div role="main" class="dashboard-refined space-y-6">
        <DashboardIntro
            :title="`Welcome back, ${page.props.auth.user.name}`"
            description="Build your portfolio, bring your software to market and keep your business moving."
            eyebrow="AUTHOR CONSOLE"
            ><template #metadata
                ><span
                    class="inline-flex items-center gap-2 text-xs text-success"
                    ><BadgeCheck class="size-4" aria-hidden="true" />{{
                        sellerStatus
                    }}</span
                ></template
            ><Button v-if="can('products.create')" as-child
                ><Link :href="products.create()"
                    >Create Product<ArrowUpRight
                        class="size-4"
                        aria-hidden="true" /></Link></Button
            ><Button as-child variant="outline"
                ><Link :href="profile.show()">Author Profile</Link></Button
            ></DashboardIntro
        >
        <section
            aria-label="Product inventory summary"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <MetricCard
                label="Your products"
                :value="total"
                description="Current owned product inventory"
                :icon="Package"
            /><MetricCard
                label="Published products"
                :value="counts.published ?? 0"
                description="Published lifecycle state"
                :icon="BadgeCheck"
                accent="emerald"
            /><MetricCard
                label="In review pipeline"
                :value="
                    Number(counts.submitted ?? 0) +
                    Number(counts.under_review ?? 0)
                "
                description="Submitted or under review"
                :icon="Send"
                accent="purple"
            /><MetricCard
                label="Draft products"
                :value="counts.draft ?? 0"
                description="Ideas ready for your next step"
                :icon="FilePenLine"
                accent="coral"
            />
        </section>
        <div class="grid items-start gap-6 xl:grid-cols-[1.3fr_1fr]">
            <ChartCard
                title="Your product pipeline"
                description="Current product counts by lifecycle state. This is inventory, not sales performance."
                ><template #controls
                    ><Link
                        :href="products.index()"
                        class="text-xs font-semibold text-primary"
                        >My Products →</Link
                    ></template
                ><StatusDistribution :counts="counts" /></ChartCard
            ><ChartCard
                title="Your professional identity"
                description="Help businesses understand the work you do."
                ><div class="flex items-end justify-between gap-4">
                    <p class="text-4xl font-semibold tracking-tight">
                        {{ completeness.percentage
                        }}<span class="text-xl text-muted-foreground">%</span>
                    </p>
                    <span class="text-xs text-muted-foreground"
                        >Profile completeness</span
                    >
                </div>
                <div
                    class="my-5 h-2.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Professional profile completeness"
                    :aria-valuenow="completeness.percentage"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{ width: `${completeness.percentage}%` }"
                    />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        completeness.missing.length
                            ? 'Complete your professional sections to tell your business story.'
                            : 'Your professional sections are complete. Keep your portfolio up to date.'
                    }}
                </p>
                <ul
                    v-if="completeness.missing.length"
                    class="mt-4 flex flex-wrap gap-2"
                    aria-label="Profile sections to complete"
                >
                    <li
                        v-for="section in completeness.missing"
                        :key="section"
                        class="rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ section.replaceAll('_', ' ') }}
                    </li>
                </ul>
                <Button variant="outline" as-child class="mt-5"
                    ><Link :href="profile.show()"
                        ><UserRound class="size-4" aria-hidden="true" />Edit
                        professional profile</Link
                    ></Button
                ></ChartCard
            >
        </div>
        <section class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="eyebrow mb-2">YOUR FINANCIAL POSITION</p>
                    <h2 class="text-xl font-semibold">
                        Earnings, clearly separated
                    </h2>
                </div>
                <Link
                    href="/author/earnings"
                    class="text-sm font-semibold text-primary"
                    >View earnings →</Link
                >
            </div>
            <p
                v-if="!earnings.length"
                class="rounded-xl border bg-white px-5 py-4 text-sm text-muted-foreground"
            >
                No earnings position yet. Verified author sales and payout
                activity will appear here, separated by currency.
            </p>
            <Balances :balances="earnings" />
        </section>
        <section class="grid gap-4 sm:grid-cols-2">
            <Link
                href="/author/support"
                class="elevated-card flex items-center gap-4 p-6"
                ><span class="icon-tile" data-accent="purple"
                    ><LifeBuoy class="size-5" aria-hidden="true"
                /></span>
                <div class="flex-1">
                    <h2 class="font-semibold">Keep your customers moving</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Open your existing support conversations.
                    </p>
                </div>
                <ArrowUpRight
                    class="size-5 text-primary"
                    aria-hidden="true" /></Link
            ><Link
                href="/author/payouts"
                class="elevated-card flex items-center gap-4 p-6"
                ><span class="icon-tile" data-accent="emerald"
                    ><Wallet class="size-5" aria-hidden="true"
                /></span>
                <div class="flex-1">
                    <h2 class="font-semibold">Your payout workspace</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Review requests and retained payout history.
                    </p>
                </div>
                <ArrowUpRight class="size-5 text-primary" aria-hidden="true"
            /></Link>
        </section>
    </div>
</template>
