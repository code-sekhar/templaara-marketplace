<script setup lang="ts">
import { useId } from 'vue';
import { CalendarDays } from '@lucide/vue';
withDefaults(defineProps<{ untilLabel?: string }>(), { untilLabel: 'To' });
const from = defineModel<string>('from');
const until = defineModel<string>('until');
const id = useId();
</script>
<template>
    <fieldset class="date-range-filter">
        <legend class="flex items-center gap-1.5 text-xs font-medium">
            <CalendarDays class="size-3.5" aria-hidden="true" />Date range
        </legend>
        <div
            class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-end gap-2"
        >
            <label
                :for="`${id}-from`"
                class="grid gap-1 text-xs text-muted-foreground"
                >From<input
                    :id="`${id}-from`"
                    v-model="from"
                    type="date" /></label
            ><span aria-hidden="true" class="pb-3 text-muted-foreground">—</span
            ><label
                :for="`${id}-until`"
                class="grid gap-1 text-xs text-muted-foreground"
                >{{ untilLabel
                }}<input :id="`${id}-until`" v-model="until" type="date"
            /></label>
        </div>
    </fieldset>
</template>
