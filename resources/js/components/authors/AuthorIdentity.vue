<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { AuthorCard } from '@/types/author-professional';
defineProps<{ author: AuthorCard }>();
</script>
<template>
    <div class="flex min-w-0 items-start gap-4 rounded-2xl border bg-card p-5">
        <img
            v-if="author.avatar"
            :src="author.avatar.url"
            :alt="author.name + ' profile image'"
            class="h-14 w-14 shrink-0 rounded-xl object-cover"
        />
        <span
            v-else
            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-muted text-xl font-semibold"
            aria-hidden="true"
            >{{ author.name.slice(0, 1) }}</span
        >
        <div class="min-w-0 space-y-2">
            <Link
                :href="author.url"
                class="font-semibold break-words hover:underline"
                >{{ author.name }}</Link
            ><span
                v-if="author.verified"
                class="ml-2 inline-block rounded-md bg-success-soft px-2 py-1 text-xs font-medium text-success"
                title="Approved through Templaara's author application process; not government certification or a product guarantee."
                >Verified Author</span
            >
            <p
                v-if="author.headline"
                class="text-sm break-words text-muted-foreground"
            >
                {{ author.headline }}
            </p>
            <p
                v-if="author.years_of_experience !== null"
                class="text-xs text-muted-foreground"
            >
                {{ author.years_of_experience }}+ years experience
            </p>
            <Link
                :href="author.url"
                class="inline-block text-sm font-medium underline underline-offset-4"
                >View author profile ↗</Link
            >
        </div>
    </div>
</template>
