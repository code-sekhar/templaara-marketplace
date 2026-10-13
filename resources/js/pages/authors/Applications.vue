<script setup lang="ts">
import DateRangeFilter from '@/components/design/DateRangeFilter.vue';
import FilterToolbar from '@/components/design/FilterToolbar.vue';
import PageHeader from '@/components/design/PageHeader.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import * as routes from '@/routes/admin/author-applications';
import { authorStatusLabels, countryName } from '@/types/authors';
import type { AuthorStatus, Pagination } from '@/types/authors';
type Row = {
    id: number;
    display_name: string;
    company_name: string | null;
    author_type: string;
    country_code: string;
    status: AuthorStatus;
    round_number: number;
    submitted_at: string;
    applicant: { name: string; email: string };
};
const props = defineProps<{
    records: Pagination<Row>;
    countries: string[];
    filters: {
        search?: string;
        status?: string;
        author_type?: string;
        country?: string;
        from?: string;
        to?: string;
    };
}>();
const form = useForm({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    author_type: props.filters.author_type ?? '',
    country: props.filters.country ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
</script>
<template>
    <Head title="Author Applications" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <header>
            <PageHeader title="Author Applications" eyebrow="YOUR WORKSPACE" />
            <p class="mt-3 text-muted-foreground">
                Review marketplace author applications. Each row shows the
                latest submitted round.
            </p>
        </header>
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
                class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
                @submit.prevent="form.get(routes.index.url())"
            >
                <div class="grid gap-2">
                    <Label for="search">Search</Label
                    ><Input
                        id="search"
                        v-model="form.search"
                        placeholder="Display name, company or account email"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Status</Label
                    ><select
                        id="status"
                        v-model="form.status"
                        class="h-10 rounded-md border bg-background px-3"
                    >
                        <option value="">All submitted states</option>
                        <option
                            v-for="(label, value) in authorStatusLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="type">Author type</Label
                    ><select
                        id="type"
                        v-model="form.author_type"
                        class="h-10 rounded-md border bg-background px-3"
                    >
                        <option value="">All types</option>
                        <option value="individual">Individual</option>
                        <option value="company">Company</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="country">Country</Label
                    ><select
                        id="country"
                        v-model="form.country"
                        class="h-10 max-w-full rounded-md border bg-background px-3"
                    >
                        <option value="">All countries</option>
                        <option
                            v-for="code in countries"
                            :key="code"
                            :value="code"
                        >
                            {{ countryName(code) }}
                        </option>
                    </select>
                </div>
                <DateRangeFilter
                    v-model:from="form.from"
                    v-model:until="form.to"
                    until-label="Submitted to"
                />
                <Button :disabled="form.processing">{{
                    form.processing ? 'Filtering…' : 'Apply Filters'
                }}</Button
                ><Link :href="routes.index()" class="self-center underline"
                    >Clear filters</Link
                >
                <p
                    v-for="(message, key) in form.errors"
                    :key="key"
                    class="text-destructive"
                >
                    {{ message }}
                </p>
            </form></FilterToolbar
        >
        <p
            v-if="!records.data.length"
            class="rounded-lg border p-8 text-center text-muted-foreground"
        >
            No author applications found.
        </p>
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="a in records.data"
                :key="a.id"
                class="space-y-3 rounded-lg border p-5"
            >
                <h2 class="text-xl font-semibold break-words">
                    {{ a.display_name }}
                </h2>
                <p>{{ a.company_name }}</p>
                <p class="capitalize">
                    {{ a.author_type }} · {{ countryName(a.country_code) }}
                </p>
                <p class="break-all">{{ a.applicant.email }}</p>
                <p>
                    {{ authorStatusLabels[a.status] }} · Round #{{
                        a.round_number
                    }}
                </p>
                <p class="text-sm">
                    Submitted {{ new Date(a.submitted_at).toLocaleString() }}
                </p>
                <Link :href="routes.show(a.id)" class="inline-block underline"
                    >View Application</Link
                >
            </article>
        </div>
        <nav aria-label="Application pagination" class="flex justify-between">
            <Link
                v-if="records.prev_page_url"
                :href="records.prev_page_url"
                class="underline"
                >Previous</Link
            >
            <p>Page {{ records.current_page }} of {{ records.last_page }}</p>
            <Link
                v-if="records.next_page_url"
                :href="records.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
