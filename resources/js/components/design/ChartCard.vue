<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>
<template>
    <section class="chart-card elevated-card">
        <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="mt-1 max-w-xl text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <slot name="controls" />
        </header>
        <slot />
        <div
            v-if="$slots.legend"
            class="mt-5 border-t pt-4 text-xs text-muted-foreground"
        >
            <slot name="legend" />
        </div>
    </section>
</template>
