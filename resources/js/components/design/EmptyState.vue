<script setup lang="ts">
import type { Component } from 'vue';
import { PackageSearch } from '@lucide/vue';
defineProps<{
    title: string;
    description?: string;
    icon?: Component;
    illustration?: string;
}>();
</script>
<template>
    <div class="empty-state">
        <img
            v-if="illustration"
            :src="illustration"
            alt=""
            width="160"
            height="104"
            class="mx-auto mb-4"
        />
        <component
            v-else
            :is="icon ?? PackageSearch"
            aria-hidden="true"
            class="mx-auto mb-4 size-10 rounded-xl bg-brand-blue-soft p-2 text-primary"
        />
        <h2 class="text-lg font-semibold">{{ title }}</h2>
        <p
            v-if="description"
            class="mx-auto mt-2 max-w-md text-sm text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
