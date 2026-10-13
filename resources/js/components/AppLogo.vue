<script setup lang="ts">
const files = Object.keys(
    import.meta.glob(['/public/logo-w.png', '/public/brand/templaara-logo.*']),
);
const logo = files[0]?.replace('/public', '');
</script>
<template>
    <img
        v-if="logo"
        :src="logo"
        alt="Templaara"
        class="h-8 w-auto max-w-48 object-contain"
    /><span v-else class="brand-wordmark truncate">Templaara</span>
</template>
