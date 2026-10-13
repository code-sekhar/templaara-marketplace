<script setup lang="ts">
import ChartCard from '@/components/design/ChartCard.vue';
import { computed, ref, watch } from 'vue';
import type { Charts, Point } from '@/types/analytics';
const props = defineProps<{ charts: Charts }>();
const currency = ref(props.charts.series[0]?.currency ?? '');
watch(
    () => props.charts.series,
    (series) => {
        if (!series.some((s) => s.currency === currency.value))
            currency.value = series[0]?.currency ?? '';
    },
);
const metric = ref<
    'gross' | 'orders' | 'refunds' | 'commission' | 'author_earned'
>('gross');
const selected = computed(
    () =>
        props.charts.series.find((s) => s.currency === currency.value) ??
        props.charts.series[0],
);
const moneyUnits = (value: string | number): bigint =>
    BigInt(String(value).replace('.', ''));
const heights = computed(() => {
    const values = (selected.value?.points ?? []).map((p) =>
        moneyUnits(p[metric.value]),
    );
    const max = values.reduce((m, v) => (v > m ? v : m), 0n);
    // BigInt monetary arithmetic; Number only for the final bounded SVG pixel coordinate.
    return values.map((v) => (max ? Number((v * 140n) / max) : 0));
});
const label = (p: Point) =>
    `${p.bucket}: ${metric.value.replaceAll('_', ' ')} ${metric.value === 'orders' ? '' : selected.value?.currency} ${p[metric.value]}`;
const customerMax = computed(() =>
    Math.max(1, ...props.charts.newCustomers.map((p) => p.count)),
);
</script>
<template>
    <section class="grid gap-4 lg:grid-cols-2">
        <ChartCard
            title="Sales and marketplace economics"
            description="Verified commercial aggregates, one currency at a time."
        >
            <div class="my-3 flex flex-wrap gap-3 text-xs font-medium">
                <label
                    >Currency
                    <select
                        v-model="currency"
                        class="ml-2 rounded-lg border bg-white px-2 py-1.5 text-xs"
                    >
                        <option v-for="s in charts.series" :key="s.currency">
                            {{ s.currency }}
                        </option>
                    </select></label
                ><label
                    >Metric
                    <select
                        v-model="metric"
                        class="ml-2 rounded-lg border bg-white px-2 py-1.5 text-xs"
                    >
                        <option value="gross">Gross sales</option>
                        <option value="orders">Paid orders</option>
                        <option value="refunds">Completed refunds</option>
                        <option value="commission">Commission</option>
                        <option value="author_earned">Author earnings</option>
                    </select></label
                >
            </div>
            <p v-if="!selected" class="py-8 text-muted-foreground">
                No financial activity in this period.
            </p>
            <template v-else
                ><svg
                    viewBox="0 0 600 180"
                    class="w-full"
                    role="img"
                    :aria-label="`${metric} in ${selected.currency}; ${charts.granularity} buckets`"
                >
                    <line
                        v-for="y in [20, 65, 110, 160]"
                        :key="y"
                        x1="10"
                        x2="590"
                        :y1="y"
                        :y2="y"
                        stroke="#e2e8f0"
                        stroke-width="1"
                    />
                    <rect
                        rx="2"
                        v-for="(p, i) in selected.points"
                        :key="p.bucket"
                        :x="(i * 580) / selected.points.length + 10"
                        :y="160 - heights[i]"
                        :width="Math.max(1, 580 / selected.points.length - 2)"
                        :height="heights[i]"
                        :class="
                            metric === 'refunds'
                                ? 'fill-danger'
                                : metric === 'author_earned'
                                  ? 'fill-success'
                                  : metric === 'commission'
                                    ? 'fill-review'
                                    : 'fill-primary'
                        "
                    >
                        <title>{{ label(p) }}</title>
                    </rect>
                </svg>
                <p class="text-xs text-muted-foreground">
                    {{ charts.labels[0] }} — {{ charts.labels.at(-1) }} ·
                    {{ charts.granularity }}
                </p>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm">
                        Accessible chart data
                    </summary>
                    <ul class="max-h-52 overflow-auto text-sm">
                        <li v-for="p in selected.points" :key="p.bucket">
                            {{ label(p) }}
                        </li>
                    </ul>
                </details></template
            >
        </ChartCard>
        <ChartCard
            title="New customer registrations"
            description="Actual customer registrations by reporting bucket."
        >
            <p
                v-if="!charts.newCustomers.some((p) => p.count > 0)"
                class="rounded-xl border bg-muted/30 px-4 py-8 text-sm text-muted-foreground"
            >
                No new customer registrations in this period.
            </p>
            <svg
                v-else
                viewBox="0 0 600 180"
                class="mt-4 w-full"
                role="img"
                aria-label="New customers by reporting bucket"
            >
                <rect
                    v-for="(p, i) in charts.newCustomers"
                    :key="p.bucket"
                    :x="(i * 580) / charts.newCustomers.length + 10"
                    :y="160 - (p.count / customerMax) * 140"
                    :width="Math.max(1, 580 / charts.newCustomers.length - 2)"
                    :height="(p.count / customerMax) * 140"
                    class="fill-info"
                >
                    <title>{{ p.bucket }}: {{ p.count }} new customers</title>
                </rect>
            </svg>
            <details>
                <summary class="cursor-pointer text-sm">
                    Accessible chart data
                </summary>
                <ul class="max-h-52 overflow-auto text-sm">
                    <li v-for="p in charts.newCustomers" :key="p.bucket">
                        {{ p.bucket }}: {{ p.count }}
                    </li>
                </ul>
            </details>
        </ChartCard>
    </section>
</template>
