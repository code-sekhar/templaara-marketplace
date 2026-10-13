<script setup lang="ts">
defineProps<{ title: string; description?: string; eyebrow?: string }>();
</script>
<template>
    <header class="dashboard-intro">
        <div class="min-w-0 grow basis-full sm:basis-96">
            <p
                v-if="eyebrow"
                class="mb-2 text-[11px] font-semibold tracking-[0.14em] text-primary uppercase"
            >
                {{ eyebrow }}
            </p>
            <h1
                class="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl"
            >
                {{ title }}
            </h1>
            <p
                v-if="description"
                class="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground"
            >
                {{ description }}
            </p>
            <div v-if="$slots.metadata" class="mt-3">
                <slot name="metadata" />
            </div>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </header>
</template>
