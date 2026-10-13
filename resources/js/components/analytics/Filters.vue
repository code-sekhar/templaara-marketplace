<script setup lang="ts">
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Filters } from '@/types/analytics';
const props = defineProps<{
    filters: Filters;
    url: string;
    statuses?: string[];
    facets: Record<string, { id: number; name: string }[]>;
}>();
const values = ref({
    currency: '',
    origin: '',
    product: '',
    author: '',
    category: '',
    ...props.filters,
});
const busy = ref(false);
const page = usePage();
function apply() {
    const query = Object.fromEntries(
        Object.entries(values.value).filter(
            ([, v]) => v !== '' && v !== undefined,
        ),
    );
    router.get(props.url, query, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}
</script>
<template>
    <FilterToolbar
        :filters="values"
        title="Reporting period & scope"
        @clear="router.get(url)"
        @remove="
            router.get(
                url,
                Object.fromEntries(
                    Object.entries(values).filter(([key]) => key !== $event),
                ),
            )
        "
        ><form
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-3 lg:grid-cols-5"
            @submit.prevent="apply"
        >
            <label class="grid gap-1 text-sm"
                >Period<select
                    v-model="values.period"
                    aria-label="Period"
                    class="h-9 rounded-md border bg-background px-2"
                >
                    <option value="today">Today</option>
                    <option value="7days">Last 7 days</option>
                    <option value="30days">Last 30 days</option>
                    <option value="90days">Last 90 days</option>
                    <option value="month">This month</option>
                    <option value="last_month">Last month</option>
                    <option value="year">This year</option>
                    <option value="custom">Custom range</option>
                </select></label
            >
            <DateRangeFilter
                v-if="values.period === 'custom'"
                v-model:from="values.from"
                v-model:until="values.to"
                until-label="Through"
            />
            <label class="grid gap-1 text-sm"
                >Currency<select
                    v-model="values.currency"
                    aria-label="Currency"
                    class="h-9 rounded-md border bg-background px-2"
                >
                    <option value="">All currencies separately</option>
                    <option v-for="c in ['INR', 'USD', 'EUR', 'GBP']" :key="c">
                        {{ c }}
                    </option>
                </select></label
            >
            <label class="grid gap-1 text-sm"
                >Origin<select
                    v-model="values.origin"
                    aria-label="Origin"
                    class="h-9 rounded-md border bg-background px-2"
                >
                    <option value="">All origins</option>
                    <option value="author">Author</option>
                    <option value="templaara">Templaara Official</option>
                </select></label
            >
            <label
                v-for="key in ['product', 'author', 'category'] as const"
                :key="key"
                class="grid gap-1 text-sm capitalize"
                >{{ key }}
                <select
                    v-model="values[key]"
                    :aria-label="key"
                    class="h-9 rounded-md border bg-background px-2"
                >
                    <option value="">All</option>
                    <option
                        v-for="option in facets[key]"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </label>
            <label v-if="statuses?.length" class="grid gap-1 text-sm"
                >Status<select
                    v-model="values.status"
                    aria-label="Status"
                    class="h-9 rounded-md border bg-background px-2"
                >
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s" :value="s">
                        {{ s.replaceAll('_', ' ') }}
                    </option>
                </select></label
            >
            <Button class="self-end" :disabled="busy" type="submit">{{
                busy ? 'Loading…' : 'Apply filters'
            }}</Button>
            <p
                v-for="(error, field) in page.props.errors"
                :key="field"
                role="alert"
                class="text-sm text-destructive sm:col-span-3"
            >
                {{ error }}
            </p>
        </form></FilterToolbar
    >
</template>
