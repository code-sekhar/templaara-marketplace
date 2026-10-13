<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{ id: string; label: string; error?: string; help?: string }>();
</script>
<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <slot />
        <p v-if="help" class="text-sm text-muted-foreground">{{ help }}</p>
        <InputError :message="error" />
    </div>
</template>
