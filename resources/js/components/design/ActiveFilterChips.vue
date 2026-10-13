<script setup lang="ts">
import { computed } from 'vue';
import { X } from '@lucide/vue';
const props = defineProps<{
    filters?: object;
    labels?: Record<string, string>;
    valueLabels?: Record<string, string>;
}>();
defineEmits<{ remove: [key: string, value?: string] }>();
const entries = computed(() =>
    Object.entries(props.filters ?? {}).flatMap(([key, value]) => {
        if (
            ['page', 'sort', 'view'].includes(key) ||
            value === '' ||
            value === undefined ||
            value === null ||
            typeof value === 'function'
        )
            return [];
        return (Array.isArray(value) ? value : [value]).map((item) => ({
            key,
            value: String(item),
            individual: Array.isArray(value),
        }));
    }),
);
</script>
<template>
    <div
        v-if="entries.length"
        class="flex flex-wrap items-center gap-2"
        aria-label="Active filters"
    >
        <button
            v-for="{ key, value, individual } in entries"
            :key="`${key}-${value}`"
            type="button"
            class="filter-chip"
            :aria-label="`Remove ${labels?.[key] ?? key} ${valueLabels?.[value] ?? value} filter`"
            @click="$emit('remove', key, individual ? value : undefined)"
        >
            <span class="capitalize"
                >{{ labels?.[key] ?? key.replaceAll('_', ' ') }}:</span
            ><span class="max-w-40 truncate">{{
                valueLabels?.[value] ?? value.replaceAll('_', ' ')
            }}</span
            ><X class="size-3.5" aria-hidden="true" />
        </button>
    </div>
</template>
