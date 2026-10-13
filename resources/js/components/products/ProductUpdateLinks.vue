<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import * as updatesRoutes from '@/routes/products/updates';
const props = defineProps<{
    productId: number;
    canCreate: boolean;
    updates: { id: number; status: string; version: string }[];
}>();
const form = useForm({ version: '' });
</script>
<template>
    <section class="mx-auto max-w-5xl space-y-4 rounded-lg border p-5">
        <h2 class="text-lg font-semibold">Published software updates</h2>
        <p>
            Live content remains unchanged until an approved new version is
            published.
        </p>
        <Link
            v-for="update in updates"
            :key="update.id"
            :href="updatesRoutes.show([productId, update.id])"
            class="mr-4 inline-block underline"
            >Version {{ update.version }} ·
            {{ update.status.replaceAll('_', ' ') }}</Link
        >
        <form
            v-if="
                canCreate &&
                !updates.some(
                    (x) => !['published', 'rejected'].includes(x.status),
                )
            "
            class="flex flex-wrap gap-3"
            @submit.prevent="form.post(updatesRoutes.create.url(productId))"
        >
            <label for="new-version">New version</label
            ><Input
                id="new-version"
                v-model="form.version"
                placeholder="1.1.0"
                class="max-w-xs"
                required
            /><Button :disabled="form.processing">Create New Version</Button
            ><InputError :message="Object.values(form.errors).join(' ')" />
        </form>
    </section>
</template>
