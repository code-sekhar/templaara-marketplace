<script setup lang="ts">
import type { Component } from 'vue';
withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        description?: string;
        icon?: Component;
        accent?: string;
        trend?: string;
    }>(),
    { accent: 'blue' },
);
</script>
<template>
    <article class="metric-card glass-card" :data-accent="accent">
        <div class="flex items-start justify-between gap-3">
            <p class="metric-label">{{ label }}</p>
            <span v-if="icon" class="icon-tile"
                ><component :is="icon" aria-hidden="true" class="size-5"
            /></span>
        </div>
        <p class="metric-value">{{ value }}</p>
        <p v-if="description" class="metric-context">{{ description }}</p>
        <p v-if="trend" class="mt-3 text-sm font-medium">{{ trend }}</p>
        <slot />
    </article>
</template>
