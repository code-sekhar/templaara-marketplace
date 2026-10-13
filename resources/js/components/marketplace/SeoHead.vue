<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Seo } from '@/types/marketplace';
defineProps<{ seo: Seo }>();
</script>
<template>
    <Head :title="seo.title">
        <meta
            head-key="description"
            name="description"
            :content="seo.description"
        />
        <link head-key="canonical" rel="canonical" :href="seo.canonical" />
        <component
            :is="'script'"
            v-if="seo.structuredData"
            type="application/ld+json"
            head-key="software-application"
            >{{ seo.structuredData }}</component
        >
    </Head>
</template>
