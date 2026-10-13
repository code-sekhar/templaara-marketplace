<script setup lang="ts">
const files = Object.keys(
    import.meta.glob(['/public/logo.png', '/public/brand/templaara-mark.*']),
);
const mark = files[0]?.replace('/public', '');
</script>
<template>
    <img
        v-if="mark"
        :src="mark"
        alt="Templaara"
        class="size-8 object-contain"
    /><span v-else aria-label="Templaara" class="font-semibold text-primary"
        >T</span
    >
</template>
