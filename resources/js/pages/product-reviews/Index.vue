<script setup lang="ts">
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { statusLabels } from '@/lib/products';
import * as routes from '@/routes/admin/product-reviews';
import type {
    ReviewPage,
    ReviewQueueItem,
    ReviewActor,
} from '@/types/product-review';
const props = defineProps<{
    records: ReviewPage<ReviewQueueItem>;
    filters: Record<string, string>;
    categories: ReviewActor[];
    authors: ReviewActor[];
}>();
const filters = reactive({
    search: '',
    status: '',
    origin: '',
    category: '',
    author: '',
    from: '',
    to: '',
    ...props.filters,
});
const loading = ref(false);
function search() {
    router.get(
        routes.index.url(),
        { ...filters },
        {
            preserveState: true,
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
</script>
<template>
    <Head title="Product Reviews" />
    <div role="main" class="space-y-6 p-4 md:p-6">
        <Heading
            title="Product Reviews"
            description="Review business software submissions and preserve actionable decisions."
        />
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
                <div class="grid gap-2">
                    <Label for="search">Search</Label
                    ><Input
                        id="search"
                        v-model="filters.search"
                        maxlength="100"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Status</Label
                    ><select
                        id="status"
                        v-model="filters.status"
                        class="h-9 rounded-md border bg-background px-3"
                    >
                        <option value="">All review states</option>
                        <template
                            v-for="(label, value) in statusLabels"
                            :key="value"
                            ><option v-if="value !== 'draft'" :value="value">
                                {{ label }}
                            </option></template
                        >
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="origin">Origin</Label
                    ><select
                        id="origin"
                        v-model="filters.origin"
                        class="h-9 rounded-md border bg-background px-3"
                    >
                        <option value="">All origins</option>
                        <option value="author">Author</option>
                        <option value="templaara">Templaara Official</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="category">Category</Label
                    ><select
                        id="category"
                        v-model="filters.category"
                        class="h-9 max-w-52 rounded-md border bg-background px-3"
                    >
                        <option value="">All categories</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="author">Author</Label
                    ><select
                        id="author"
                        v-model="filters.author"
                        class="h-9 max-w-52 rounded-md border bg-background px-3"
                    >
                        <option value="">All authors</option>
                        <option
                            v-for="author in authors"
                            :key="author.id"
                            :value="author.id"
                        >
                            {{ author.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="from">Submitted from</Label
                    ><Input id="from" v-model="filters.from" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label for="to">Submitted to</Label
                    ><Input id="to" v-model="filters.to" type="date" />
                </div>
                <Button :disabled="loading">Apply</Button
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="
                        Object.assign(filters, {
                            search: '',
                            status: '',
                            origin: '',
                            category: '',
                            author: '',
                            from: '',
                            to: '',
                        });
                        search();
                    "
                    >Reset</Button
                >
            </form></FilterToolbar
        >
        <div class="overflow-x-auto rounded-lg border" :aria-busy="loading">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Latest product submission rounds
                </caption>
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="p-3">Product</th>
                        <th class="p-3">Author / Origin</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Submission</th>
                        <th class="p-3">Reviewer</th>
                        <th class="p-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="round in records.data"
                        :key="round.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3">
                            {{ round.product.name
                            }}<span class="block text-muted-foreground">{{
                                round.product.category.name
                            }}</span>
                        </td>
                        <td class="p-3">
                            {{
                                round.product.origin === 'templaara'
                                    ? 'Templaara Official'
                                    : round.product.owner?.name
                            }}
                        </td>
                        <td class="p-3">
                            <StatusBadge :status="round.product.status" />
                        </td>
                        <td class="p-3">
                            #{{ round.round_number }} ·
                            {{
                                new Date(
                                    round.submitted_at,
                                ).toLocaleDateString()
                            }}
                        </td>
                        <td class="p-3">
                            {{ round.reviewer?.name ?? 'Unclaimed' }}
                        </td>
                        <td class="p-3">
                            <Button variant="outline" size="sm" as-child
                                ><Link :href="routes.show(round.id)"
                                    >Review</Link
                                ></Button
                            >
                        </td>
                    </tr>
                    <tr v-if="!records.data.length">
                        <td
                            colspan="6"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No submissions match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap justify-between gap-3 text-sm">
            <p>
                Page {{ records.current_page }} of {{ records.last_page }} ·
                {{ records.total }} submissions
            </p>
            <div class="flex gap-2">
                <Button v-if="records.prev_page_url" variant="outline" as-child
                    ><Link :href="records.prev_page_url">Previous</Link></Button
                ><Button v-if="records.next_page_url" variant="outline" as-child
                    ><Link :href="records.next_page_url">Next</Link></Button
                >
            </div>
        </div>
    </div>
</template>
