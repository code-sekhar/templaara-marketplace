<script setup lang="ts">
import CatalogFacet from '@/components/marketplace/CatalogFacet.vue';
import ActiveFilterChips from '@/components/design/ActiveFilterChips.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import { show as categoryShow } from '@/routes/marketplace/categories';
import { Link, router, usePage } from '@inertiajs/vue3';
import { LayoutGrid, List } from '@lucide/vue';
import { useElementSize, useWindowSize } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import ProductCard from '@/components/marketplace/ProductCard.vue';
import SeoHead from '@/components/marketplace/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/marketplace/products';
import type {
    CatalogLink,
    MarketplaceFilters,
    ProductCardData,
    Seo,
} from '@/types/marketplace';
const props = defineProps<{
    seo: Seo;
    filters: MarketplaceFilters;
    context: {
        kind: string;
        name: string;
        slug: string;
        description: string | null;
    } | null;
    categories: CatalogLink[];
    industries: CatalogLink[];
    technologies: CatalogLink[];
    records: {
        data: ProductCardData[];
        total: number;
        from: number | null;
        to: number | null;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const filters = reactive<MarketplaceFilters>({
    ...props.filters,
    sort:
        typeof props.filters.sort === 'string'
            ? props.filters.sort
            : 'recommended',
});
const page = usePage();
const loading = ref(false);
const sidebar = ref<HTMLElement | null>(null);
const { height: sidebarHeight } = useElementSize(sidebar);
const { height: viewportHeight } = useWindowSize();
watch(
    () => props.filters,
    (value) => {
        for (const key of Object.keys(filters) as (keyof MarketplaceFilters)[])
            delete filters[key];
        Object.assign(filters, value);
        if (typeof filters.sort !== 'string') filters.sort = 'recommended';
    },
);
const valueLabels = computed(() =>
    Object.fromEntries(
        [...props.categories, ...props.industries, ...props.technologies].map(
            (item) => [item.slug, item.name],
        ),
    ),
);
function switchView(view: 'grid' | 'list') {
    router.get(
        props.seo.canonical,
        { ...props.filters, view, page: props.records.current_page },
        { preserveState: true, preserveScroll: true },
    );
}
function clear() {
    router.get(
        props.seo.canonical,
        { view: filters.view, sort: filters.sort },
        { preserveScroll: true },
    );
}
function remove(key: string, value?: string) {
    const field = key as keyof MarketplaceFilters;
    const current = filters[field];
    if (Array.isArray(current) && value)
        Object.assign(filters, {
            [key]: current.filter((item) => item !== value),
        });
    else delete filters[field];
    apply();
}
function apply() {
    if ((filters.min_price || filters.max_price) && !filters.currency)
        filters.currency = 'INR';
    router.get(
        props.seo.canonical,
        Object.fromEntries(
            Object.entries(filters).filter(
                ([key, v]) =>
                    key !== 'page' && (Array.isArray(v) ? v.length > 0 : v),
            ),
        ),
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}
</script>
<template>
    <SeoHead :seo="seo" />
    <div class="catalog-page space-y-7">
        <header>
            <p class="text-sm text-muted-foreground">
                Find your next business solution
            </p>
            <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">
                {{ seo.title }}
            </h1>
            <p
                v-if="context?.description"
                class="mt-3 max-w-3xl text-muted-foreground"
            >
                {{ context.description }}
            </p>
        </header>
        <div class="grid items-start gap-8 lg:grid-cols-[250px_1fr]">
            <aside
                ref="sidebar"
                class="elevated-card p-5"
                :class="{
                    'catalog-sidebar': sidebarHeight + 112 < viewportHeight,
                }"
            >
                <FilterToolbar
                    class="catalog-toolbar"
                    :desktop-width="1024"
                    title="Find software"
                    :filters="filters"
                    :show-chips="false"
                    @clear="clear"
                    @remove="remove"
                >
                    <form
                        id="catalog-filters"
                        class="space-y-4"
                        @submit.prevent="apply"
                    >
                        <div
                            v-if="Object.keys(page.props.errors).length"
                            role="alert"
                            class="rounded-md border border-destructive p-3 text-sm text-destructive"
                        >
                            <p
                                v-for="(error, key) in page.props.errors"
                                :key="key"
                            >
                                {{ error }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="catalog-search"
                                class="mb-2 block text-sm"
                                >Business need</label
                            ><Input
                                id="catalog-search"
                                v-model="filters.search"
                                type="search"
                                maxlength="100"
                                placeholder="Inventory, payroll, CRM…"
                            />
                        </div>
                        <CatalogFacet
                            v-if="context?.kind !== 'category'"
                            name="category"
                            label="Categories"
                            :items="categories"
                            :selected="
                                filters.category ? [filters.category] : []
                            "
                            :multiple="false"
                            expanded
                            @update="filters.category = $event[0]"
                        />
                        <CatalogFacet
                            v-if="context?.kind !== 'industry'"
                            name="industries"
                            label="Industries"
                            :items="industries"
                            :selected="
                                filters.industries ??
                                (filters.industry ? [filters.industry] : [])
                            "
                            @update="
                                filters.industries = $event;
                                delete filters.industry;
                            "
                        />
                        <CatalogFacet
                            name="technologies"
                            label="Technologies"
                            :items="technologies"
                            :selected="
                                filters.technologies ??
                                (filters.technology ? [filters.technology] : [])
                            "
                            @update="
                                filters.technologies = $event;
                                delete filters.technology;
                            "
                        />
                        <div>
                            <label for="currency" class="mb-2 block text-sm"
                                >Price currency</label
                            ><select
                                id="currency"
                                v-model="filters.currency"
                                class="w-full rounded-md border bg-background p-2 text-sm"
                            >
                                <option :value="undefined">
                                    All currencies
                                </option>
                                <option
                                    v-for="currency in [
                                        'INR',
                                        'USD',
                                        'EUR',
                                        'GBP',
                                    ]"
                                    :key="currency"
                                >
                                    {{ currency }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Price ranges default to INR. Currencies are
                                never converted.
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label
                                    for="min-price"
                                    class="mb-2 block text-sm"
                                    >Min price</label
                                ><Input
                                    id="min-price"
                                    v-model="filters.min_price"
                                    type="text"
                                    inputmode="decimal"
                                    pattern="[0-9]+([.][0-9]{1,2})?"
                                    placeholder="0"
                                />
                            </div>
                            <div>
                                <label
                                    for="max-price"
                                    class="mb-2 block text-sm"
                                    >Max price</label
                                ><Input
                                    id="max-price"
                                    v-model="filters.max_price"
                                    type="text"
                                    inputmode="decimal"
                                    pattern="[0-9]+([.][0-9]{1,2})?"
                                    placeholder="Any"
                                />
                            </div>
                        </div>
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="loading"
                            >{{
                                loading ? 'Finding software…' : 'Apply filters'
                            }}</Button
                        ><button
                            type="button"
                            class="block w-full text-center text-xs font-medium text-muted-foreground hover:text-primary"
                            @click="clear"
                        >
                            Clear all
                        </button>
                    </form>
                </FilterToolbar>
            </aside>
            <section
                :aria-busy="loading"
                aria-label="Software results"
                class="min-w-0 space-y-6"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <p aria-live="polite" class="text-sm text-muted-foreground">
                        {{ records.total }}
                        {{ records.total === 1 ? 'solution' : 'solutions' }}
                        found
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            role="group"
                            aria-label="Catalog view"
                            class="flex rounded-lg border bg-white p-1"
                        >
                            <button
                                v-for="mode in ['grid', 'list'] as const"
                                :key="mode"
                                type="button"
                                :aria-label="`${mode === 'grid' ? 'Grid' : 'List'} view`"
                                :aria-pressed="
                                    (filters.view ?? 'grid') === mode
                                "
                                class="rounded-md p-2 focus-visible:ring-2 focus-visible:ring-ring"
                                :class="{
                                    'bg-brand-blue-soft text-primary':
                                        (filters.view ?? 'grid') === mode,
                                }"
                                @click="switchView(mode)"
                            >
                                <component
                                    :is="mode === 'grid' ? LayoutGrid : List"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                        <label for="sort" class="text-sm">Sort</label
                        ><select
                            id="sort"
                            v-model="filters.sort"
                            class="rounded-md border bg-background p-2 text-sm"
                            @change="apply"
                        >
                            <option value="recommended">Recommended</option>
                            <option value="newest">Newest</option>
                            <option value="price_asc">
                                Price: Low to High
                            </option>
                            <option value="price_desc">
                                Price: High to Low
                            </option>
                        </select>
                    </div>
                </div>
                <ActiveFilterChips
                    :filters="props.filters"
                    :value-labels="valueLabels"
                    :labels="{
                        industries: 'Industry',
                        technologies: 'Technology',
                    }"
                    @remove="remove"
                />
                <p
                    v-if="
                        filters.sort?.startsWith('price') && !filters.currency
                    "
                    class="text-xs text-muted-foreground"
                >
                    Prices are sorted within each currency. Choose a currency to
                    compare like for like.
                </p>
                <div
                    v-if="records.data.length"
                    :class="
                        filters.view === 'list'
                            ? 'grid gap-4'
                            : 'grid gap-5 sm:grid-cols-2 xl:grid-cols-3'
                    "
                >
                    <ProductCard
                        v-for="product in records.data"
                        :key="product.slug"
                        :product="product"
                        :mode="filters.view ?? 'grid'"
                    />
                </div>
                <EmptyState
                    illustration="/illustrations/catalog-discovery.svg"
                    v-else
                    :title="
                        context
                            ? `No matching software for ${context.name}`
                            : 'Let’s find your next business tool'
                    "
                    description="Try a different business need, or explore a category to discover available software."
                    ><div class="mt-4 flex flex-wrap justify-center gap-2">
                        <Link
                            v-for="category in categories.slice(0, 4)"
                            :key="category.slug"
                            :href="categoryShow(category.slug)"
                            class="rounded-lg border bg-white px-3 py-2 text-xs font-medium text-primary"
                            >{{ category.name }}</Link
                        >
                    </div>
                    <Link
                        :href="index()"
                        class="mt-5 inline-block text-sm font-semibold text-primary"
                        >Clear filters &amp; browse software →</Link
                    ></EmptyState
                >
                <nav
                    v-if="records.last_page > 1"
                    aria-label="Results pagination"
                    class="flex items-center justify-between gap-3"
                >
                    <Link
                        v-if="records.prev_page_url"
                        :href="records.prev_page_url"
                        class="rounded-lg border px-4 py-2 text-sm"
                        >Previous</Link
                    ><span v-else /><span class="text-sm text-muted-foreground"
                        >Page {{ records.current_page }} of
                        {{ records.last_page }}</span
                    ><Link
                        v-if="records.next_page_url"
                        :href="records.next_page_url"
                        class="rounded-lg border px-4 py-2 text-sm"
                        >Next</Link
                    ><span v-else />
                </nav>
            </section>
        </div>
    </div>
</template>
