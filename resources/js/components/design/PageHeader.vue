<script setup lang="ts">
defineProps<{ title: string; description?: string; eyebrow?: string }>();
</script>
<template>
    <header class="premium-page-header">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow mb-3">{{ eyebrow }}</p>
            <h1 class="page-heading">{{ title }}</h1>
            <p
                v-if="description"
                class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground"
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
