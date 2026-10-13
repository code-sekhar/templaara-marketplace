<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/admin/product-updates';
import { show } from '@/routes/products/updates';
const props = defineProps<{
    records: {
        data: {
            id: number;
            product_id: number;
            status: string;
            product: { name: string; origin: string };
            release: { version: string };
        }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { status?: string; search?: string };
}>();
const form = useForm({
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
});
</script>
<template>
    <Head title="Product Update Reviews" />
    <div role="main" class="space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Product Update Reviews</h1>
        <form
            class="flex flex-wrap gap-3"
            @submit.prevent="form.get(index.url())"
        >
            <Input
                v-model="form.search"
                placeholder="Search software"
                aria-label="Search software"
                class="max-w-sm"
            /><select
                v-model="form.status"
                aria-label="Update status"
                class="rounded-md border p-2"
            >
                <option value="">All review states</option>
                <option
                    v-for="status in [
                        'submitted',
                        'under_review',
                        'changes_requested',
                        'approved',
                        'rejected',
                        'published',
                    ]"
                    :key="status"
                    :value="status"
                >
                    {{ status.replaceAll('_', ' ') }}
                </option></select
            ><Button :disabled="form.processing">Filter</Button>
        </form>
        <article
            v-for="r in records.data"
            :key="r.id"
            class="rounded-lg border p-5"
        >
            <Link
                :href="show([r.product_id, r.id])"
                class="font-semibold underline"
                >{{ r.product.name }} · {{ r.release.version }}</Link
            >
            <p>
                <StatusBadge :status="r.status" /> ·
                {{
                    r.product.origin === 'templaara'
                        ? 'Templaara Official'
                        : 'Author product'
                }}
            </p>
        </article>
        <p v-if="!records.data.length">No updates match these filters.</p>
        <nav class="flex gap-4">
            <Link v-if="records.prev_page_url" :href="records.prev_page_url"
                >Previous</Link
            ><Link v-if="records.next_page_url" :href="records.next_page_url"
                >Next</Link
            >
        </nav>
    </div>
</template>
