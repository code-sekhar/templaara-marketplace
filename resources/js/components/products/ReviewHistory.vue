<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { feedbackSections, statusLabels } from '@/lib/products';
import type { ReviewHistory } from '@/types/product-review';
defineProps<{ history: ReviewHistory; status: string }>();
const date = (value: string) => new Date(value).toLocaleString();
</script>
<template>
    <section
        class="space-y-4 rounded-lg border p-5"
        :class="
            status === 'changes_requested'
                ? 'border-amber-500 bg-amber-50/30 dark:bg-amber-950/20'
                : ''
        "
    >
        <h2 class="text-lg font-semibold">Review / Submission History</h2>
        <p>
            Current status:
            <Badge variant="secondary">{{ statusLabels[status] }}</Badge>
        </p>
        <p v-if="status === 'changes_requested'" class="font-medium">
            Address the requested changes below, save your edits, then resubmit
            for a new review round.
        </p>
        <p
            v-if="!history.rounds.data.length"
            class="text-sm text-muted-foreground"
        >
            This product has not been submitted yet.
        </p>
        <article
            v-for="round in history.rounds.data"
            :key="round.id"
            class="space-y-3 rounded-lg border bg-background p-4"
        >
            <h3 class="font-semibold">Submission #{{ round.round_number }}</h3>
            <p class="text-sm">
                Submitted by {{ round.submitter.name }} ·
                {{ date(round.submitted_at) }}
            </p>
            <p v-if="round.review_started_at" class="text-sm">
                Review started by {{ round.reviewer?.name }} ·
                {{ date(round.review_started_at) }}
            </p>
            <p v-else class="text-sm text-muted-foreground">Awaiting review</p>
            <p v-if="round.decision" class="font-medium">
                {{ statusLabels[round.decision] }} ·
                {{ round.decision_at ? date(round.decision_at) : '' }}
            </p>
            <p v-if="round.decision_summary" class="whitespace-pre-wrap">
                {{ round.decision_summary }}
            </p>
            <ul v-if="round.feedback.length" class="space-y-3">
                <li
                    v-for="item in round.feedback"
                    :key="item.id"
                    class="rounded-md border-l-4 border-amber-500 bg-muted/40 p-3"
                >
                    <strong>{{ feedbackSections[item.section] }}</strong>
                    <p class="whitespace-pre-wrap">{{ item.message }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ item.creator.name }}
                    </p>
                </li>
            </ul>
        </article>
        <div
            v-if="history.rounds.last_page > 1"
            class="flex flex-wrap items-center gap-3 text-sm"
        >
            <span
                >Rounds: page {{ history.rounds.current_page }} of
                {{ history.rounds.last_page }}</span
            ><Button
                v-if="history.rounds.prev_page_url"
                variant="outline"
                size="sm"
                as-child
                ><Link :href="history.rounds.prev_page_url" preserve-scroll
                    >Newer rounds</Link
                ></Button
            ><Button
                v-if="history.rounds.next_page_url"
                variant="outline"
                size="sm"
                as-child
                ><Link :href="history.rounds.next_page_url" preserve-scroll
                    >Older rounds</Link
                ></Button
            >
        </div>
        <details
            v-if="history.events.data.length"
            class="rounded-md border p-3"
        >
            <summary class="cursor-pointer font-medium">
                Lifecycle audit trail
            </summary>
            <ol class="mt-3 space-y-2 text-sm">
                <li v-for="entry in history.events.data" :key="entry.id">
                    {{ statusLabels[entry.from_status] }} →
                    {{ statusLabels[entry.to_status] }} ·
                    {{ entry.actor.name }} · {{ date(entry.occurred_at) }}
                </li>
            </ol>
            <div class="mt-3 flex gap-3">
                <Link
                    v-if="history.events.prev_page_url"
                    :href="history.events.prev_page_url"
                    class="underline"
                    preserve-scroll
                    >Newer events</Link
                ><Link
                    v-if="history.events.next_page_url"
                    :href="history.events.next_page_url"
                    class="underline"
                    preserve-scroll
                    >Older events</Link
                >
            </div>
        </details>
    </section>
</template>
