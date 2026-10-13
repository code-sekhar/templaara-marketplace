<script setup lang="ts">
import { computed, ref } from 'vue';
import { useMediaQuery } from '@vueuse/core';
import { SlidersHorizontal, RotateCcw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import ActiveFilterChips from './ActiveFilterChips.vue';
const props = defineProps<{
    compact?: boolean;
    filters?: object;
    title?: string;
    desktopWidth?: number;
    showChips?: boolean;
}>();
const emit = defineEmits<{ clear: []; remove: [key: string] }>();
const desktop = useMediaQuery(
    computed(() => `(min-width: ${props.desktopWidth ?? 768}px)`),
);
const open = ref(false);
const count = computed(() =>
    Object.entries(props.filters ?? {})
        .filter(
            ([k, v]) =>
                !['page', 'sort', 'view'].includes(k) &&
                (!Array.isArray(v) || v.length > 0) &&
                v !== '' &&
                v !== undefined &&
                v !== null &&
                typeof v !== 'function',
        )
        .reduce(
            (sum, [, value]) => sum + (Array.isArray(value) ? value.length : 1),
            0,
        ),
);
</script>
<template>
    <section class="filter-toolbar" :aria-label="title ?? 'Filter results'">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm font-semibold">
                <SlidersHorizontal
                    class="size-4 text-primary"
                    aria-hidden="true"
                />{{ title ?? 'Refine your results'
                }}<span v-if="count" class="filter-count">{{ count }}</span>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="!desktop"
                    type="button"
                    variant="outline"
                    @click="open = true"
                    >Filters<span v-if="count">({{ count }})</span></Button
                ><button
                    v-if="count"
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary"
                    @click="emit('clear')"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />Clear
                    filters
                </button>
            </div>
        </div>
        <div
            v-if="desktop"
            class="filter-fields mt-4"
            :class="{ 'filter-fields--compact': compact }"
        >
            <slot />
        </div>
        <ActiveFilterChips
            v-if="count && showChips !== false"
            class="mt-4"
            :filters="filters"
            @remove="emit('remove', $event)"
        />
        <Sheet v-if="!desktop" v-model:open="open"
            ><SheetContent
                class="filter-drawer overflow-y-auto"
                @submit.capture="open = false"
                ><SheetHeader
                    ><SheetTitle>{{ title ?? 'Filter results' }}</SheetTitle
                    ><SheetDescription
                        >Choose the results you want to see. Apply filters to
                        update the list.</SheetDescription
                    ></SheetHeader
                >
                <div
                    class="filter-fields px-5 pb-6"
                    :class="{ 'filter-fields--compact': compact }"
                >
                    <slot /></div></SheetContent
        ></Sheet>
    </section>
</template>
