<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAuthorization } from '@/composables/useAuthorization';
import { catalogLabels, catalogRoutes } from '@/lib/catalog';
import type {
    CatalogKind,
    CatalogParent,
    CatalogRecord,
} from '@/types/catalog';

const props = defineProps<{
    kind: CatalogKind;
    record: CatalogRecord | null;
    parents: CatalogParent[];
}>();
const labels = computed(() => catalogLabels[props.kind]);
const routes = computed(() => catalogRoutes[props.kind]);
const title = computed(
    () =>
        `${props.record ? 'Edit' : 'Create'} ${labels.value.singular.toLowerCase()}`,
);
const { can } = useAuthorization();
const form = useForm({
    name: props.record?.name ?? '',
    slug: props.record?.slug ?? '',
    description: props.record?.description ?? '',
    icon: props.record?.icon ?? '',
    logo: props.record?.logo ?? '',
    website_url: props.record?.website_url ?? '',
    parent_id: props.record?.parent_id ?? '',
    sort_order: props.record?.sort_order ?? 0,
    is_active: props.record?.is_active ?? true,
});
function submit() {
    if (props.record) form.put(routes.value.update.url(props.record.id));
    else form.post(routes.value.store.url());
}
</script>

<template>
    <Head :title="title" />
    <div role="main" class="max-w-3xl space-y-6 p-4 md:p-6">
        <Heading :title="title" :description="labels.description" />
        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label
                ><Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="255"
                /><InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="slug">Slug</Label
                ><Input
                    id="slug"
                    v-model="form.slug"
                    maxlength="200"
                    placeholder="Generated from name when blank"
                />
                <p class="text-sm text-muted-foreground">
                    Use lowercase letters, numbers and single hyphens. Changing
                    a slug changes its future public address.
                </p>
                <InputError :message="form.errors.slug" />
            </div>
            <div v-if="kind === 'categories'" class="grid gap-2">
                <Label for="parent_id">Parent category</Label
                ><select
                    id="parent_id"
                    v-model="form.parent_id"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">No parent (root category)</option>
                    <option
                        v-for="parent in parents"
                        :key="parent.id"
                        :value="parent.id"
                    >
                        {{ parent.name }}
                    </option></select
                ><InputError :message="form.errors.parent_id" />
            </div>
            <div class="grid gap-2">
                <Label for="description">Description</Label
                ><textarea
                    id="description"
                    v-model="form.description"
                    rows="4"
                    maxlength="5000"
                    class="rounded-md border bg-background p-3 text-sm"
                /><InputError :message="form.errors.description" />
            </div>
            <div v-if="kind !== 'technologies'" class="grid gap-2">
                <Label for="icon">Icon</Label
                ><Input
                    id="icon"
                    v-model="form.icon"
                    maxlength="255"
                    placeholder="Optional icon identifier or asset path"
                /><InputError :message="form.errors.icon" />
            </div>
            <template v-else>
                <div class="grid gap-2">
                    <Label for="logo">Logo</Label
                    ><Input
                        id="logo"
                        v-model="form.logo"
                        maxlength="255"
                        placeholder="Optional asset path"
                    /><InputError :message="form.errors.logo" />
                </div>
                <div class="grid gap-2">
                    <Label for="website_url">Website URL</Label
                    ><Input
                        id="website_url"
                        v-model="form.website_url"
                        type="url"
                        maxlength="2048"
                        placeholder="https://example.com"
                    /><InputError :message="form.errors.website_url" />
                </div>
            </template>
            <div class="grid gap-2">
                <Label for="sort_order">Sort order</Label
                ><Input
                    id="sort_order"
                    v-model="form.sort_order"
                    type="number"
                    min="0"
                    max="1000000"
                    step="1"
                    required
                /><InputError :message="form.errors.sort_order" />
            </div>
            <div class="grid gap-2">
                <Label for="is_active" class="flex items-center gap-2"
                    ><input
                        id="is_active"
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />Active</Label
                ><InputError :message="form.errors.is_active" />
            </div>
            <div class="flex gap-3">
                <Button
                    v-if="can(`${kind}.${record ? 'update' : 'create'}`)"
                    :disabled="form.processing"
                    ><Spinner v-if="form.processing" />Save
                    {{ labels.singular.toLowerCase() }}</Button
                ><Button variant="outline" as-child
                    ><Link :href="routes.index()">Cancel</Link></Button
                >
            </div>
        </form>
    </div>
</template>
