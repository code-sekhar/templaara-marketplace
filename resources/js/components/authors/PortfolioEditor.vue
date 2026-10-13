<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    PortfolioItem,
    TaxonomyOption,
} from '@/types/author-professional';
import * as routes from '@/routes/author/portfolio';
const props = defineProps<{
    items: PortfolioItem[];
    technologies: TaxonomyOption[];
    revision: number;
    editable: boolean;
}>();
const selected = ref<string | null>(null),
    open = ref(false);
const form = useForm({
    title: '',
    short_description: '',
    description: '',
    project_type: 'personal',
    project_url: '',
    case_study_url: '',
    repository_url: '',
    client_name: '',
    completion_year: '' as string | number,
    is_public: false,
    is_featured: false,
    sort_order: 0,
    revision: 0,
    technology_ids: [] as number[],
});
const upload = useForm({
    file: null as File | null,
    purpose: 'portfolio',
    revision: props.revision,
});
const removal = useForm({ revision: 0 });
function edit(item?: PortfolioItem) {
    selected.value = item?.public_uuid ?? null;
    form.reset();
    form.clearErrors();
    if (item)
        Object.assign(
            form,
            Object.fromEntries(
                Object.keys(form.data()).map((key) => [
                    key,
                    key === 'technology_ids'
                        ? item.technologies.map((t) => t.id)
                        : (item[key as keyof PortfolioItem] ?? ''),
                ]),
            ),
        );
    open.value = true;
}
function save() {
    if (selected.value)
        form.put(routes.update.url(selected.value), {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
    else
        form.post(routes.store.url(), {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
}
function remove(item: PortfolioItem) {
    if (
        !window.confirm(
            'Remove this portfolio item? Submitted application evidence will be retained.',
        )
    )
        return;
    removal.revision = item.revision;
    removal.delete(routes.destroy.url(item.public_uuid), {
        preserveScroll: true,
    });
}
function image(event: Event, item: PortfolioItem) {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.revision = props.revision;
    if (upload.file)
        upload.post(routes.media.url(item.public_uuid), {
            preserveScroll: true,
            onSuccess: () => upload.reset('file'),
        });
}
function move(index: number, direction: number) {
    const ids = props.items.map((i) => i.public_uuid);
    const other = index + direction;
    [ids[index], ids[other]] = [ids[other], ids[index]];
    useForm({ items: ids, revision: props.revision }).post(
        routes.reorder.url(),
        { preserveScroll: true },
    );
}
</script>
<template>
    <section
        id="portfolio"
        class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold">Professional work</h2>
                <p class="mt-1 max-w-xl text-sm text-muted-foreground">
                    Show what you have built. Client names are optional; respect
                    confidentiality. Up to 12 projects.
                </p>
            </div>
            <Button
                v-if="editable"
                type="button"
                :disabled="items.length >= 12"
                @click="edit()"
                >Add project</Button
            >
        </div>
        <p
            v-if="!items.length"
            class="rounded-xl border border-dashed p-7 text-sm text-muted-foreground"
        >
            Your work belongs here. Add a project, or explain confidential work
            in your professional background.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
            <article
                v-for="(item, index) in items"
                :key="item.public_uuid"
                class="min-w-0 overflow-hidden rounded-xl border"
            >
                <img
                    v-if="item.image"
                    :src="item.image.url"
                    :alt="item.title + ' preview'"
                    class="aspect-video w-full object-cover"
                    loading="lazy"
                />
                <div
                    v-else
                    class="flex aspect-video items-center justify-center bg-muted text-sm text-muted-foreground"
                >
                    Project preview
                </div>
                <div class="space-y-3 p-4">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ item.project_type.replace('_', ' ') }} ·
                        {{ item.is_public ? 'Public' : 'Not publicly listed' }}
                    </p>
                    <h3 class="text-lg font-semibold break-words">
                        {{ item.title }}
                    </h3>
                    <p class="text-sm break-words text-muted-foreground">
                        {{ item.short_description }}
                    </p>
                    <div v-if="editable" class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="edit(item)"
                            >Edit project</Button
                        ><Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="remove(item)"
                            >Remove</Button
                        ><Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="index === 0"
                            :aria-label="'Move ' + item.title + ' up'"
                            @click="move(index, -1)"
                            >↑</Button
                        ><Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="index === items.length - 1"
                            :aria-label="'Move ' + item.title + ' down'"
                            @click="move(index, 1)"
                            >↓</Button
                        >
                    </div>
                    <label v-if="editable" class="block text-sm"
                        >Project image<input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="mt-2 block w-full text-xs"
                            :disabled="upload.processing"
                            @change="image($event, item)"
                    /></label>
                </div>
            </article>
        </div>
        <p
            v-for="(error, key) in { ...upload.errors, ...removal.errors }"
            :key="key"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
        <form
            v-if="open && editable"
            class="space-y-4 rounded-xl bg-muted/30 p-5"
            @submit.prevent="save"
        >
            <h3 class="text-lg font-semibold">
                {{ selected ? 'Edit project' : 'New project' }}
            </h3>
            <label class="grid gap-2 text-sm"
                >Project title<Input
                    v-model="form.title"
                    maxlength="160"
                    required
            /></label>
            <label class="grid gap-2 text-sm"
                >Short description<textarea
                    v-model="form.short_description"
                    class="rounded-md border bg-background p-3"
                    maxlength="500"
                    rows="3"
                    required
                />
            </label>
            <label class="grid gap-2 text-sm"
                >Case study / details (optional)<textarea
                    v-model="form.description"
                    class="rounded-md border bg-background p-3"
                    maxlength="5000"
                    rows="4"
                />
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-2 text-sm"
                    >Project type<select
                        v-model="form.project_type"
                        class="rounded-md border bg-background p-2"
                    >
                        <option value="personal">Personal project</option>
                        <option value="client">Client project</option>
                        <option value="company">Company project</option>
                        <option value="open_source">Open source</option>
                    </select></label
                ><label class="grid gap-2 text-sm"
                    >Completion year (optional)<Input
                        v-model="form.completion_year"
                        type="number"
                        min="1900"
                        :max="new Date().getFullYear()"
                /></label>
            </div>
            <label
                v-for="key in [
                    'project_url',
                    'case_study_url',
                    'repository_url',
                ] as const"
                :key="key"
                class="grid gap-2 text-sm"
                >{{ key.replaceAll('_', ' ') }} (HTTPS, optional)<Input
                    v-model="form[key]"
                    type="url"
            /></label>
            <label class="grid gap-2 text-sm"
                >Client/company name (optional, public if project is
                public)<Input v-model="form.client_name" maxlength="150"
            /></label>
            <fieldset>
                <legend class="mb-2 text-sm font-medium">Technologies</legend>
                <div class="flex flex-wrap gap-3">
                    <label
                        v-for="t in technologies"
                        :key="t.id"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.technology_ids"
                            type="checkbox"
                            :value="t.id"
                        />{{ t.name }}</label
                    >
                </div>
            </fieldset>
            <div class="flex flex-wrap gap-5 text-sm">
                <label class="flex items-center gap-2"
                    ><input v-model="form.is_public" type="checkbox" />Display
                    publicly</label
                ><label class="flex items-center gap-2"
                    ><input
                        v-model="form.is_featured"
                        type="checkbox"
                    />Highlight project</label
                >
            </div>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ error }}
            </p>
            <div class="flex gap-3">
                <Button :disabled="form.processing">Save project</Button
                ><Button type="button" variant="outline" @click="open = false"
                    >Cancel</Button
                >
            </div>
        </form>
    </section>
</template>
