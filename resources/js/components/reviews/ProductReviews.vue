<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { show } from '@/routes/marketplace/products';
import ReviewContent from './ReviewContent.vue';
import type { PublicReviews } from '@/types/reviews';
const props = defineProps<{ reviews: PublicReviews; slug: string }>();
function sort(e: Event) {
    router.get(
        show.url(props.slug),
        { review_sort: (e.target as HTMLSelectElement).value },
        { preserveScroll: true },
    );
}
</script>
<template>
    <section id="customer-reviews" class="space-y-5">
        <h2 class="text-2xl font-semibold">Verified customer reviews</h2>
        <div v-if="reviews.summary.count" class="space-y-3">
            <p class="text-xl">
                {{ reviews.summary.average }} / 5 ·
                {{ reviews.summary.count }} verified
                {{ reviews.summary.count === 1 ? 'review' : 'reviews' }}
            </p>
            <ol class="space-y-1">
                <li v-for="n in [5, 4, 3, 2, 1]" :key="n">
                    {{ n }} stars: {{ reviews.summary.distribution[n] }}
                </li>
            </ol>
        </div>
        <p v-else>
            No reviews yet. Ratings appear after a qualifying customer shares
            their experience.
        </p>
        <Link
            v-if="reviews.entry.url"
            :href="reviews.entry.url"
            class="inline-block rounded border p-3"
            >{{ reviews.entry.label }}</Link
        ><label class="block"
            >Sort reviews<select
                :value="reviews.sort"
                class="ml-3 rounded border p-2"
                @change="sort"
            >
                <option value="newest">Newest</option>
                <option value="highest_rating">Highest rating</option>
                <option value="lowest_rating">Lowest rating</option>
            </select></label
        ><ReviewContent
            v-for="r in reviews.records.data"
            :key="r.uuid"
            :review="r"
        />
        <nav class="flex gap-5" aria-label="Review pages">
            <Link
                v-if="reviews.records.prev_page_url"
                :href="reviews.records.prev_page_url"
                preserve-scroll
                >Previous reviews</Link
            ><Link
                v-if="reviews.records.next_page_url"
                :href="reviews.records.next_page_url"
                preserve-scroll
                >More reviews</Link
            >
        </nav>
    </section>
</template>
