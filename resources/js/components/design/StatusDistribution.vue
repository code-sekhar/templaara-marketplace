<script setup lang="ts">
import { computed } from 'vue';
import { statusPresentation } from '@/lib/presentation';
const props = defineProps<{ counts: Record<string, number> }>();
const rows = computed(() =>
    Object.entries(props.counts).map(([name, count]) => ({
        name: statusPresentation(name).label,
        count: Number(count),
    })),
);
const max = computed(() => Math.max(1, ...rows.value.map((r) => r.count)));
</script>
<template>
    <div
        v-if="rows.length"
        class="space-y-4"
        aria-label="Product status distribution"
    >
        <div v-for="row in rows" :key="row.name">
            <div class="mb-2 flex justify-between gap-3 text-sm">
                <span>{{ row.name }}</span
                ><strong>{{ row.count }}</strong>
            </div>
            <div
                class="h-2.5 overflow-hidden rounded-full bg-muted"
                aria-hidden="true"
            >
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{ width: `${(row.count / max) * 100}%` }"
                />
            </div>
        </div>
    </div>
    <p v-else class="py-4 text-sm text-muted-foreground">
        Your product pipeline will appear after you create a product.
    </p>
</template>
