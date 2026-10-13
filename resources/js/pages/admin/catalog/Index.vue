<script setup lang="ts">
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAuthorization } from '@/composables/useAuthorization';
import { catalogLabels, catalogRoutes } from '@/lib/catalog';
import type {
    CatalogFilters,
    CatalogKind,
    CatalogPagination,
    CatalogParent,
    CatalogRecord,
} from '@/types/catalog';

const props = defineProps<{
    kind: CatalogKind;
    records: CatalogPagination;
    filters: CatalogFilters;
    parents: CatalogParent[];
}>();
const { can } = useAuthorization();
const labels = computed(() => catalogLabels[props.kind]);
const routes = computed(() => catalogRoutes[props.kind]);
const filters = reactive({ search: '', active: '', sort: 'order', parent: '' });
watch(
    () => [props.kind, props.filters],
    () => {
        Object.assign(filters, {
            search: '',
            active: '',
            sort: 'order',
            parent: '',
            ...props.filters,
        });
    },
    { immediate: true },
);
const loading = ref(false);
function search() {
    router.get(
        routes.value.index.url(),
        { ...filters },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function reset() {
    Object.assign(filters, {
        search: '',
        active: '',
        sort: 'order',
        parent: '',
    });
    search();
}
const target = ref<CatalogRecord | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref('');
function confirmDelete(record: CatalogRecord) {
    target.value = record;
    deleteError.value = '';
    deleteOpen.value = true;
}
function remove() {
    if (!target.value) return;
    router.delete(routes.value.destroy.url(target.value.id), {
        preserveScroll: true,
        onStart: () => {
            deleting.value = true;
        },
        onFinish: () => {
            deleting.value = false;
        },
        onSuccess: () => {
            deleteOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value =
                errors.delete ?? 'Unable to delete this record.';
        },
    });
}
</script>

<template>
    <Head :title="labels.plural" />
    <div role="main" class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="labels.plural" :description="labels.description" />
            <Button v-if="can(`${kind}.create`)" as-child
                ><Link :href="routes.create()"
                    >Create {{ labels.singular.toLowerCase() }}</Link
                ></Button
            >
        </div>
        <FilterToolbar
            :filters="filters"
            @clear="router.get($page.url.split('?')[0])"
            @remove="
                router.get(
                    $page.url.split('?')[0],
                    Object.fromEntries(
                        Object.entries(filters).filter(
                            ([key]) => key !== $event,
                        ),
                    ),
                )
            "
            ><form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="search"
            >
                <div class="grid min-w-48 flex-1 gap-2">
                    <Label for="search">Search</Label
                    ><Input
                        id="search"
                        v-model="filters.search"
                        maxlength="100"
                        placeholder="Name or slug"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="active">Status</Label
                    ><select
                        id="active"
                        v-model="filters.active"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div v-if="kind === 'categories'" class="grid gap-2">
                    <Label for="parent">Parent</Label
                    ><select
                        id="parent"
                        v-model="filters.parent"
                        class="h-9 max-w-64 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All parents</option>
                        <option value="root">Root categories</option>
                        <option
                            v-for="parent in parents"
                            :key="parent.id"
                            :value="String(parent.id)"
                        >
                            {{ parent.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="sort">Sort</Label
                    ><select
                        id="sort"
                        v-model="filters.sort"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="order">Sort order</option>
                        <option value="name">Name</option>
                        <option value="newest">Newest</option>
                    </select>
                </div>
                <Button :disabled="loading"
                    ><Spinner v-if="loading" />Apply</Button
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="reset"
                    >Reset</Button
                >
            </form></FilterToolbar
        >
        <div class="overflow-x-auto rounded-lg border" :aria-busy="loading">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    {{
                        labels.plural
                    }}
                    management
                </caption>
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="p-3">Name</th>
                        <th v-if="kind === 'categories'" class="p-3">Parent</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Sort order</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="record in records.data"
                        :key="record.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3">
                            <div class="font-medium">{{ record.name }}</div>
                            <div class="text-muted-foreground">
                                {{ record.slug }}
                            </div>
                        </td>
                        <td v-if="kind === 'categories'" class="p-3">
                            {{ record.parent?.name ?? 'Root category' }}
                        </td>
                        <td class="p-3">
                            {{ record.is_active ? 'Active' : 'Inactive' }}
                        </td>
                        <td class="p-3">{{ record.sort_order }}</td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="can(`${kind}.update`)"
                                    variant="outline"
                                    size="sm"
                                    as-child
                                    ><Link :href="routes.edit(record.id)"
                                        >Edit</Link
                                    ></Button
                                ><Button
                                    v-if="can(`${kind}.delete`)"
                                    variant="destructive"
                                    size="sm"
                                    @click="confirmDelete(record)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="records.data.length === 0">
                        <td
                            :colspan="kind === 'categories' ? 5 : 4"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No {{ labels.plural.toLowerCase() }} found. Adjust
                            the filters or create a record.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <p>
                Page {{ records.current_page }} of {{ records.last_page }} ·
                {{ records.total }} records
            </p>
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    :disabled="!records.prev_page_url"
                    as-child
                    ><Link
                        :href="records.prev_page_url ?? '#'"
                        :aria-disabled="!records.prev_page_url"
                        preserve-scroll
                        >Previous</Link
                    ></Button
                ><Button
                    variant="outline"
                    :disabled="!records.next_page_url"
                    as-child
                    ><Link
                        :href="records.next_page_url ?? '#'"
                        :aria-disabled="!records.next_page_url"
                        preserve-scroll
                        >Next</Link
                    ></Button
                >
            </div>
        </div>
        <Dialog v-model:open="deleteOpen">
            <DialogContent
                ><DialogHeader
                    ><DialogTitle>Delete {{ target?.name }}?</DialogTitle
                    ><DialogDescription
                        >This permanently removes this catalog record.
                        Categories with children must be reassigned
                        first.</DialogDescription
                    ></DialogHeader
                ><InputError :message="deleteError" /><DialogFooter
                    ><Button
                        variant="outline"
                        :disabled="deleting"
                        @click="deleteOpen = false"
                        >Cancel</Button
                    ><Button
                        variant="destructive"
                        :disabled="deleting"
                        @click="remove"
                        ><Spinner v-if="deleting" />Delete</Button
                    ></DialogFooter
                ></DialogContent
            >
        </Dialog>
    </div>
</template>
