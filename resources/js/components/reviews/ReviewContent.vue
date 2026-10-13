<script setup lang="ts">
import type { ReviewRow } from '@/types/reviews';
defineProps<{ review: ReviewRow }>();
</script>
<template>
    <article class="space-y-3 rounded-xl border p-5">
        <p :aria-label="`${review.rating} out of 5 stars`">
            {{ '★'.repeat(review.rating) }}{{ '☆'.repeat(5 - review.rating) }} ·
            {{ review.rating }}/5
        </p>
        <h3 v-if="review.title" class="font-semibold break-words">
            {{ review.title }}
        </h3>
        <p class="break-words whitespace-pre-line">{{ review.body }}</p>
        <p class="text-sm text-muted-foreground">
            {{ review.reviewer
            }}<span v-if="review.verified_purchase"> · Verified Purchase</span>
            · Purchased version {{ review.purchased_version }}
        </p>
        <p class="text-xs">
            {{ new Date(review.published_at).toLocaleDateString()
            }}<span v-if="review.edited_at">
                · Edited
                {{ new Date(review.edited_at).toLocaleDateString() }}</span
            >
        </p>
        <div v-if="review.response" class="space-y-2 rounded-lg bg-muted p-4">
            <h4 class="font-medium">
                {{ review.response.seller }} — Seller response
            </h4>
            <p class="break-words whitespace-pre-line">
                {{ review.response.body }}
            </p>
            <p v-if="review.response.edited_at" class="text-xs">
                Response edited
                {{ new Date(review.response.edited_at).toLocaleDateString() }}
            </p>
        </div>
    </article>
</template>
