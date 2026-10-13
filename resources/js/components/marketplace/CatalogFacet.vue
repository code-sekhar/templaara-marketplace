<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronDown, Search } from '@lucide/vue';
import type { CatalogLink } from '@/types/marketplace';
const props = withDefaults(
    defineProps<{
        name: string;
        label: string;
        items: CatalogLink[];
        selected: string[];
        multiple?: boolean;
        expanded?: boolean;
    }>(),
    { multiple: true, expanded: false },
);
const emit = defineEmits<{ update: [values: string[]] }>();
const search = ref('');
const choices = computed(() =>
    props.items.filter((item) =>
        item.name.toLowerCase().includes(search.value.toLowerCase()),
    ),
);
function toggle(slug: string) {
    emit(
        'update',
        props.multiple
            ? props.selected.includes(slug)
                ? props.selected.filter((value) => value !== slug)
                : [...props.selected, slug]
            : [slug],
    );
}
</script>
<template>
    <details :open="expanded" class="catalog-facet border-t py-4">
        <summary
            class="flex cursor-pointer list-none items-center justify-between gap-2 text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
        >
            <span
                >{{ label }}
                <span
                    v-if="selected.length"
                    class="ml-1 rounded bg-brand-blue-soft px-1.5 py-0.5 text-xs text-primary"
                    >{{ selected.length }}</span
                ></span
            ><ChevronDown class="size-4" aria-hidden="true" />
        </summary>
        <div class="mt-3 space-y-3">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-3.5 text-muted-foreground"
                    aria-hidden="true"
                /><input
                    :id="`find-${name}`"
                    v-model="search"
                    type="search"
                    :aria-label="`Search ${label.toLowerCase()}`"
                    :placeholder="`Find ${label.toLowerCase()}…`"
                    class="h-9 w-full rounded-lg border bg-white pr-2 pl-8 text-xs focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>
            <button
                v-if="!multiple"
                type="button"
                class="text-xs text-primary hover:underline"
                @click="emit('update', [])"
            >
                All categories
            </button>
            <div
                class="max-h-44 space-y-1 overflow-y-auto overscroll-contain pr-1"
                :aria-label="label"
            >
                <label
                    v-for="item in choices"
                    :key="item.slug"
                    class="flex min-h-8 cursor-pointer items-start gap-2.5 rounded-md p-1.5 text-xs leading-5 hover:bg-brand-blue-soft"
                >
                    <input
                        :type="multiple ? 'checkbox' : 'radio'"
                        :name="name"
                        :value="item.slug"
                        :checked="selected.includes(item.slug)"
                        class="mt-1 size-3.5 shrink-0 accent-primary"
                        @change="toggle(item.slug)"
                    /><span>{{ item.name }}</span>
                </label>
                <p
                    v-if="!choices.length"
                    class="py-2 text-xs text-muted-foreground"
                >
                    No matching {{ label.toLowerCase() }}.
                </p>
            </div>
            <p v-if="multiple" class="text-[11px] text-muted-foreground">
                Matches any selected
                {{ name === 'industries' ? 'industry' : 'technology' }}.
            </p>
        </div>
    </details>
</template>
