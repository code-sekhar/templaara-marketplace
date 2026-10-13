<script setup lang="ts">
import { computed } from 'vue';
import { statusPresentation } from '@/lib/presentation';
const props = defineProps<{ status?: string | null }>();
const presentation = computed(() =>
    statusPresentation(props.status ?? 'Not specified'),
);
</script>
<template>
    <span class="status-badge" :class="`status-${presentation.tone}`">{{
        presentation.label
    }}</span>
</template>
