<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthorSnapshot from './AuthorSnapshot.vue';
import { authorStatusLabels } from '@/types/authors';
import type { AuthorHistory } from '@/types/authors';
defineProps<{ history: AuthorHistory }>();
</script>
<template>
    <section class="space-y-4">
        <h2 class="text-xl font-semibold">Application history</h2>
        <article
            v-for="round in history.rounds.data"
            :key="round.id"
            class="space-y-4 rounded-lg border p-5"
        >
            <h3 class="font-semibold">
                Round #{{ round.round_number }} ·
                {{ authorStatusLabels[round.status] }}
            </h3>
            <p class="text-sm">
                Submitted {{ new Date(round.submitted_at).toLocaleString() }}
            </p>
            <p
                v-if="round.decision_summary"
                class="break-words whitespace-pre-wrap"
            >
                {{ round.decision_summary }}
            </p>
            <div
                v-if="round.feedback.length"
                class="space-y-3 rounded-lg border border-amber-500 p-4"
            >
                <h4 class="font-semibold">Requested changes</h4>
                <p
                    v-for="(item, i) in round.feedback"
                    :key="i"
                    class="break-words whitespace-pre-wrap"
                >
                    <span class="font-semibold capitalize"
                        >{{ item.section }}:</span
                    >
                    {{ item.message }}
                </p>
            </div>
            <details>
                <summary class="cursor-pointer">
                    Submitted profile (frozen)
                </summary>
                <AuthorSnapshot :content="round.snapshot" class="mt-3" />
            </details>
            <details>
                <summary class="cursor-pointer">Audit history</summary>
                <ul class="mt-2 space-y-2 text-sm">
                    <li v-for="(event, i) in round.events" :key="i">
                        {{ event.action.replaceAll('_', ' ') }} ·
                        {{ authorStatusLabels[event.from_status] }} →
                        {{ authorStatusLabels[event.to_status] }} ·
                        {{ new Date(event.occurred_at).toLocaleString() }} ·
                        {{ event.actor_name }}
                    </li>
                </ul>
            </details>
        </article>
        <div class="flex justify-between">
            <Link
                v-if="history.rounds.prev_page_url"
                :href="history.rounds.prev_page_url"
                preserve-scroll
                class="underline"
                >Previous rounds</Link
            ><Link
                v-if="history.rounds.next_page_url"
                :href="history.rounds.next_page_url"
                preserve-scroll
                class="underline"
                >Older rounds</Link
            >
        </div>
    </section>
</template>
