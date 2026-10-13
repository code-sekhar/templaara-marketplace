<script setup lang="ts">
import EmptyState from '@/components/design/EmptyState.vue';
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import type { ReviewPage as Page } from '@/types/reviews';
import type { ReviewRow } from '@/types/reviews';
import * as customer from '@/routes/account/reviews';
import * as adminRoutes from '@/routes/admin/reviews';
import * as authorRoutes from '@/routes/author/reviews';
const props = defineProps<{
    records: Page<ReviewRow>;
    filters: Record<string, string>;
    admin: boolean;
    author: boolean;
}>();
const routes = props.admin
    ? adminRoutes
    : props.author
      ? authorRoutes
      : customer;
const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    rating: props.filters.rating ?? '',
    product: props.filters.product ?? '',
    customer: props.filters.customer ?? '',
    author: props.filters.author ?? '',
    response: props.filters.response ?? '',
    from: props.filters.from ?? '',
    until: props.filters.until ?? '',
});
function filter() {
    router.get(routes.index.url(), filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head
        :title="
            admin
                ? 'Review Moderation'
                : author
                  ? 'Customer Reviews'
                  : 'My Reviews'
        "
    />
    <div
        role="main"
        class="space-y-6 p-4 sm:p-6"
        :class="{ 'customer-workspace': !admin && !author }"
    >
        <PageHeader
            :title="
                admin
                    ? 'Review Moderation'
                    : author
                      ? 'Customer Reviews'
                      : 'My Reviews'
            "
            eyebrow="YOUR WORKSPACE"
            description="Your verified purchase reviews and seller responses."
        />
        <FilterToolbar
            :compact="!admin && !author"
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
            ><form class="flex flex-wrap gap-3" @submit.prevent="filter">
                <label class="workspace-filter-label"
                    ><span>Search reviews</span
                    ><input
                        v-model="filters.search"
                        aria-label="Search reviews"
                        placeholder="Search reviews"
                        class="rounded border p-3" /></label
                ><label class="workspace-filter-label"
                    ><span>Product slug</span
                    ><input
                        v-model="filters.product"
                        aria-label="Product slug"
                        placeholder="Product slug"
                        class="rounded border p-3" /></label
                ><label class="workspace-filter-label"
                    ><span>Rating</span
                    ><select
                        v-model="filters.rating"
                        aria-label="Rating"
                        class="rounded border p-3"
                    >
                        <option value="">All ratings</option>
                        <option
                            v-for="n in [5, 4, 3, 2, 1]"
                            :key="n"
                            :value="n"
                        >
                            {{ n }} stars
                        </option>
                    </select></label
                ><label v-if="!author" class="workspace-filter-label"
                    ><span>Status</span
                    ><select
                        v-model="filters.status"
                        aria-label="Status"
                        class="rounded border p-3"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="s in [
                                'published',
                                'hidden',
                                'withdrawn',
                                'unqualified',
                            ]"
                            :key="s"
                        >
                            {{ s }}
                        </option>
                    </select></label
                ><label class="workspace-filter-label"
                    ><span>Seller response</span
                    ><select
                        v-model="filters.response"
                        aria-label="Seller response"
                        class="rounded border p-3"
                    >
                        <option value="">All responses</option>
                        <option value="answered">Answered</option>
                        <option value="unanswered">Unanswered</option>
                    </select></label
                ><template v-if="admin"
                    ><label class="workspace-filter-label"
                        ><span>Customer name</span
                        ><input
                            v-model="filters.customer"
                            aria-label="Customer name"
                            placeholder="Customer name"
                            class="rounded border p-3" /></label
                    ><label class="workspace-filter-label"
                        ><span>Author name</span
                        ><input
                            v-model="filters.author"
                            aria-label="Author name"
                            placeholder="Author name"
                            class="rounded border p-3" /></label></template
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
            title="No reviews match"
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
            v-for="r in records.data"
            :key="r.uuid"
            class="workspace-record space-y-3 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold break-words">
                {{ r.product?.name }}
            </h2>
            <p>
                {{ r.rating }} / 5 · <StatusBadge :status="r.status" /><span
                    v-if="r.withdrawn_at"
                >
                    · Withdrawn</span
                ><span v-if="!r.verification_retained">
                    · No qualifying purchase</span
                >
            </p>
            <p class="line-clamp-3 break-words whitespace-pre-line">
                {{ r.body }}
            </p>
            <p class="text-sm">
                {{ new Date(r.published_at).toLocaleDateString()
                }}<span v-if="r.edited_at">
                    · Edited
                    {{ new Date(r.edited_at).toLocaleDateString() }}</span
                >
                · {{ r.response ? 'Seller responded' : 'No response yet' }}
            </p>
            <Link :href="routes.show(r.uuid)" class="underline">{{
                admin
                    ? 'Moderate'
                    : author
                      ? 'View / Respond'
                      : 'View / Edit Review'
            }}</Link>
        </article>
        <nav class="flex gap-5">
            <Link v-if="records.prev_page_url" :href="records.prev_page_url"
                >Previous</Link
            ><Link v-if="records.next_page_url" :href="records.next_page_url"
                >Next</Link
            >
        </nav>
    </div>
</template>
