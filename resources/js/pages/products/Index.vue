<script setup lang="ts">
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
import { productRoutes, statusLabels } from '@/lib/products';
import type {
    CatalogOption,
    ProductContext,
    ProductFilters,
    ProductListItem,
    ProductPagination,
} from '@/types/product';

const props = defineProps<{
    context: ProductContext;
    records: ProductPagination;
    filters: ProductFilters;
    categories: CatalogOption[];
    canCreate: boolean;
}>();
const routes = computed(() => productRoutes[props.context]);
const title = computed(() =>
    props.context === 'admin' ? 'All Products' : 'My Products',
);
const { can } = useAuthorization();
const filters = reactive({ search: '', category: '', status: '', origin: '' });
watch(
    () => [props.context, props.filters],
    () => {
        Object.assign(filters, {
            search: '',
            category: '',
            status: '',
            origin: '',
            ...props.filters,
        });
    },
    { immediate: true },
);
const loading = ref(false);
const submission = useForm({});
function submit(product: ProductListItem) {
    submission.post(routes.value.submit.url(product.id), {
        preserveScroll: true,
    });
}
function search() {
    const query = {
        search: filters.search,
        category: filters.category,
        status: filters.status,
        ...(props.context === 'admin' ? { origin: filters.origin } : {}),
    };
    router.get(routes.value.index.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}
function reset() {
    Object.assign(filters, {
        search: '',
        category: '',
        status: '',
        origin: '',
    });
    search();
}
const target = ref<ProductListItem | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref('');
function confirmDelete(product: ProductListItem) {
    target.value = product;
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
            deleteError.value = errors.delete ?? 'Unable to delete this draft.';
        },
    });
}
</script>
<template>
    <Head :title="title" />
    <div role="main" class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="title"
                description="Develop software drafts around the business problems they solve."
            />
            <Button v-if="canCreate && can('products.create')" as-child
                ><Link :href="routes.create()">{{
                    context === 'admin'
                        ? 'Create official product'
                        : 'Create product'
                }}</Link></Button
            >
        </div>
        <p class="text-sm text-muted-foreground">
            Products remain private here. Status controls their review
            lifecycle.
        </p>
        <div
            v-if="Object.keys(submission.errors).length"
            class="rounded-lg border border-destructive p-4"
            role="alert"
        >
            <p class="font-medium">Complete these items before submitting:</p>
            <InputError
                v-for="(error, field) in submission.errors"
                :key="field"
                :message="error"
            />
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
                        placeholder="Product name or slug"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="category">Category</Label
                    ><select
                        id="category"
                        v-model="filters.category"
                        class="h-9 max-w-64 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All categories</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="String(category.id)"
                        >
                            {{ category.name
                            }}{{ category.is_active ? '' : ' (inactive)' }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="status">Status</Label
                    ><select
                        id="status"
                        v-model="filters.status"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="(label, value) in statusLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
                <div v-if="context === 'admin'" class="grid gap-2">
                    <Label for="origin">Origin</Label
                    ><select
                        id="origin"
                        v-model="filters.origin"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All origins</option>
                        <option value="author">Author</option>
                        <option value="templaara">Templaara Official</option>
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
                        title
                    }}
                </caption>
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="p-3">Product</th>
                        <th class="p-3">Category</th>
                        <th v-if="context === 'admin'" class="p-3">
                            Owner / Origin
                        </th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in records.data"
                        :key="product.id"
                        class="border-b last:border-0"
                    >
                        <td class="min-w-48 p-3">
                            <div class="font-medium">{{ product.name }}</div>
                            <p class="max-w-md text-muted-foreground">
                                {{ product.short_description }}
                            </p>
                        </td>
                        <td class="p-3">{{ product.category.name }}</td>
                        <td v-if="context === 'admin'" class="p-3">
                            {{
                                product.origin === 'templaara'
                                    ? 'Templaara Official'
                                    : (product.owner?.name ?? 'Author')
                            }}
                        </td>
                        <td class="p-3">
                            <StatusBadge :status="product.status" /><span
                                v-if="!product.is_active"
                                class="block text-muted-foreground"
                                >Inactive</span
                            >
                        </td>
                        <td class="p-3">
                            <div class="flex flex-wrap justify-end gap-2">
                                <Button
                                    v-if="
                                        product.can.submit &&
                                        can('products.submit')
                                    "
                                    size="sm"
                                    :disabled="submission.processing"
                                    @click="submit(product)"
                                >
                                    {{
                                        product.status === 'changes_requested'
                                            ? 'Resubmit'
                                            : 'Submit for Review'
                                    }}
                                </Button>
                                <Button
                                    v-if="product.can.view"
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="routes.show(product.id)">{{
                                        product.status === 'changes_requested'
                                            ? 'View Feedback'
                                            : product.status === 'rejected'
                                              ? 'View Decision'
                                              : 'View'
                                    }}</Link></Button
                                ><Button
                                    v-if="
                                        product.can.update &&
                                        can('products.update')
                                    "
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="routes.edit(product.id)"
                                        >Edit</Link
                                    ></Button
                                ><Button
                                    v-if="
                                        product.can.delete &&
                                        can('products.delete')
                                    "
                                    size="sm"
                                    variant="destructive"
                                    @click="confirmDelete(product)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="records.data.length === 0">
                        <td
                            :colspan="context === 'admin' ? 5 : 4"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No products found. Adjust your filters or create
                            your first draft.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <p>
                Page {{ records.current_page }} of {{ records.last_page }} ·
                {{ records.total }} products
            </p>
            <div class="flex gap-2">
                <Button v-if="records.prev_page_url" variant="outline" as-child
                    ><Link :href="records.prev_page_url" preserve-scroll
                        >Previous</Link
                    ></Button
                ><Button v-else variant="outline" disabled>Previous</Button
                ><Button v-if="records.next_page_url" variant="outline" as-child
                    ><Link :href="records.next_page_url" preserve-scroll
                        >Next</Link
                    ></Button
                ><Button v-else variant="outline" disabled>Next</Button>
            </div>
        </div>
        <Dialog v-model:open="deleteOpen"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>Delete {{ target?.name }}?</DialogTitle
                    ><DialogDescription
                        >This permanently removes the draft, its features,
                        offers and catalog links. Catalog records remain
                        available.</DialogDescription
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
                        ><Spinner v-if="deleting" />Delete draft</Button
                    ></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </div>
</template>
