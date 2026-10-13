<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import * as routes from '@/routes/admin/analytics';
defineProps<{ reports: string[] }>();
</script>
<template>
    <Head title="Marketplace reports" />
    <main class="space-y-6 p-4 md:p-8">
        <h1 class="text-2xl font-semibold">Marketplace reports</h1>
        <p class="text-muted-foreground">
            Read-only facts from settled purchases and retained domain history.
            Each currency is reported separately.
        </p>
        <Link :href="routes.dashboard()" class="underline"
            >Marketplace dashboard</Link
        >
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="report in reports"
                :key="report"
                :href="routes.show(report)"
                class="rounded-lg border p-6 font-medium capitalize hover:bg-muted"
                >{{ report }}</Link
            >
        </div>
    </main>
</template>
