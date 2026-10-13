<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import type { Pagination, AuthorStatus } from '@/types/authors';
import { authorStatusLabels, countryName } from '@/types/authors';
import { index } from '@/routes/admin/authors';
const props = defineProps<{
    filters: Record<string, string>;
    countries: string[];
    records: Pagination<{
        display_name: string | null;
        slug: string;
        status: AuthorStatus;
        author_type: string;
        country_code: string;
        years_of_experience: number | null;
        is_legacy: boolean;
        completeness: { percentage: number };
        application_url: string | null;
    }>;
}>();
const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    author_type: props.filters.author_type ?? '',
    country_code: props.filters.country_code ?? '',
});
</script>
<template>
    <Head title="Author directory" />
    <div role="main" class="mx-auto max-w-6xl space-y-6 p-4 sm:p-7">
        <header>
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Professional identity
            </p>
            <h1 class="mt-2 text-3xl font-semibold">Author directory</h1>
            <p class="mt-3 text-muted-foreground">
                Inspect professional profile completeness and open the retained
                application review.
            </p>
        </header>
        <form
            class="flex flex-wrap gap-3 rounded-xl border p-4"
            @submit.prevent="
                router.get(index.url(), filters, { preserveState: true })
            "
        >
            <Input
                v-model="filters.search"
                aria-label="Search authors"
                placeholder="Search display name"
                class="w-full sm:w-64"
            /><select
                v-model="filters.status"
                aria-label="Status"
                class="rounded-md border bg-background p-2"
            >
                <option value="">All statuses</option>
                <option
                    v-for="(label, key) in authorStatusLabels"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option></select
            ><select
                v-model="filters.author_type"
                aria-label="Author type"
                class="rounded-md border bg-background p-2"
            >
                <option value="">All types</option>
                <option value="individual">Individual</option>
                <option value="company">Company</option></select
            ><select
                v-model="filters.country_code"
                aria-label="Country"
                class="max-w-full rounded-md border bg-background p-2"
            >
                <option value="">All countries</option>
                <option v-for="c in countries" :key="c" :value="c">
                    {{ countryName(c) }}
                </option></select
            ><Button>Apply filters</Button>
        </form>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="author in records.data"
                :key="author.slug"
                class="space-y-3 rounded-xl border p-5"
            >
                <h2 class="text-lg font-semibold break-words">
                    {{ author.display_name || 'Incomplete profile' }}
                </h2>
                <p class="text-sm">
                    {{ authorStatusLabels[author.status] }} ·
                    {{ author.author_type }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        author.years_of_experience === null
                            ? 'Experience not provided'
                            : author.years_of_experience + ' years experience'
                    }}
                    · {{ author.completeness.percentage }}% complete
                </p>
                <Link
                    v-if="author.application_url"
                    :href="author.application_url"
                    class="inline-block text-sm underline"
                    >Inspect application</Link
                ><Link
                    v-if="author.status === 'approved'"
                    :href="'/authors/' + author.slug"
                    class="ml-3 inline-block text-sm underline"
                    >Public profile</Link
                >
            </article>
        </div>
        <p
            v-if="!records.data.length"
            class="rounded-xl border border-dashed p-7 text-muted-foreground"
        >
            No authors match these filters.
        </p>
        <nav class="flex flex-wrap gap-3" aria-label="Author pagination">
            <Link
                v-if="records.prev_page_url"
                :href="records.prev_page_url"
                class="rounded-lg border px-4 py-2"
                >Previous</Link
            ><span class="px-3 py-2"
                >Page {{ records.current_page }} of
                {{ records.last_page }}</span
            ><Link
                v-if="records.next_page_url"
                :href="records.next_page_url"
                class="rounded-lg border px-4 py-2"
                >Next</Link
            >
        </nav>
    </div>
</template>
