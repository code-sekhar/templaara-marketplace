<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ProductRecord } from '@/types/product';
import type { ProductFileLimits, ProductMedia } from '@/types/product-files';
import * as mediaRoutes from '@/routes/products/files/media';
import * as releaseRoutes from '@/routes/products/files/releases';
import * as artifactRoutes from '@/routes/products/files/artifacts';
const props = defineProps<{
    product: ProductRecord;
    limits: ProductFileLimits;
}>();
const cover = computed(() =>
    props.product.media?.find((item) => item.type === 'cover'),
);
const screenshots = computed(
    () =>
        props.product.media?.filter((item) => item.type === 'screenshot') ?? [],
);
const prepared = computed(() => props.product.releases?.[0]);
const coverForm = useForm({ type: 'cover', files: [] as File[] });
const screenshotForm = useForm({
    type: 'screenshot',
    files: [] as File[],
    replace_id: null as number | null,
});
const releaseForm = useForm({
    version: '1.0.0',
    changelog: '',
    release_notes: '',
});
const packageForm = useForm({
    type: 'source_package',
    file: null as File | null,
});
const docForm = useForm({ type: 'documentation', file: null as File | null });
const mutation = useForm({ sort_order: 0, alt_text: '' });
watch(
    () =>
        JSON.stringify([
            prepared.value?.id,
            prepared.value?.version,
            prepared.value?.changelog,
            prepared.value?.release_notes,
        ]),
    () => {
        const value = prepared.value;
        releaseForm.defaults({
            version: value?.version ?? '1.0.0',
            changelog: value?.changelog ?? '',
            release_notes: value?.release_notes ?? '',
        });
        releaseForm.reset();
    },
    { immediate: true },
);
function selected(event: Event) {
    return Array.from((event.target as HTMLInputElement).files ?? []);
}
function uploadCover() {
    coverForm.post(mediaRoutes.store.url(props.product.id), {
        preserveScroll: true,
        onSuccess: () => coverForm.reset(),
    });
}
function uploadScreenshots() {
    screenshotForm.post(mediaRoutes.store.url(props.product.id), {
        preserveScroll: true,
        onSuccess: () => screenshotForm.reset(),
    });
}
function saveRelease() {
    const options = { preserveScroll: true };
    if (prepared.value)
        releaseForm.patch(
            releaseRoutes.update.url([props.product.id, prepared.value.id]),
            options,
        );
    else releaseForm.post(releaseRoutes.store.url(props.product.id), options);
}
function uploadArtifact(documentation: boolean) {
    if (!prepared.value) return;
    const form = documentation ? docForm : packageForm;
    form.post(artifactRoutes.store.url([props.product.id, prepared.value.id]), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function removeMedia(item: ProductMedia) {
    if (!window.confirm('Remove this image from the current product?')) return;
    mutation.delete(mediaRoutes.destroy.url([props.product.id, item.id]), {
        preserveScroll: true,
    });
}
function metadata(
    item: ProductMedia,
    event: Event,
    field: 'alt_text' | 'sort_order',
) {
    mutation.alt_text =
        field === 'alt_text'
            ? (event.target as HTMLInputElement).value
            : (item.alt_text ?? '');
    mutation.sort_order =
        field === 'sort_order'
            ? Number((event.target as HTMLInputElement).value)
            : item.sort_order;
    mutation.patch(mediaRoutes.update.url([props.product.id, item.id]), {
        preserveScroll: true,
    });
}
function removeArtifact(id: number) {
    if (
        !prepared.value ||
        !window.confirm('Remove this file from the current release?')
    )
        return;
    mutation.delete(
        artifactRoutes.destroy.url([props.product.id, prepared.value.id, id]),
        { preserveScroll: true },
    );
}
const busy = computed(
    () =>
        coverForm.processing ||
        screenshotForm.processing ||
        releaseForm.processing ||
        packageForm.processing ||
        docForm.processing ||
        mutation.processing,
);
const size = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;
const errors = computed(() =>
    [
        coverForm,
        screenshotForm,
        releaseForm,
        packageForm,
        docForm,
        mutation,
    ].flatMap((form) => Object.values(form.errors)),
);
</script>
<template>
    <div class="space-y-6">
        <div
            v-if="errors.length"
            role="alert"
            class="rounded-lg border border-destructive p-4 text-destructive"
        >
            <p v-for="(error, index) in errors" :key="index">{{ error }}</p>
        </div>
        <section class="space-y-5 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Media</h2>
            <form class="space-y-3" @submit.prevent="uploadCover">
                <Label for="cover-file">Cover image</Label>
                <img
                    v-if="cover?.url"
                    :src="cover.url"
                    :alt="cover.alt_text || 'Product cover'"
                    class="max-h-60 max-w-full rounded-lg"
                />
                <p v-if="cover" class="text-sm">
                    {{ cover.original_name }} · {{ size(cover.size_bytes) }}
                </p>
                <Input
                    :key="cover?.id"
                    id="cover-file"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    :disabled="busy"
                    @change="coverForm.files = selected($event)"
                />
                <p class="text-sm text-muted-foreground">
                    JPEG, PNG or WebP · max {{ limits.cover_max_kb / 1024 }} MB
                    · minimum 320 × 180.
                </p>
                <progress
                    v-if="coverForm.progress"
                    :value="coverForm.progress.percentage"
                    max="100"
                    aria-label="Cover upload progress"
                />
                <div class="flex gap-3">
                    <Button :disabled="busy || !coverForm.files.length">{{
                        cover ? 'Replace cover' : 'Upload cover'
                    }}</Button
                    ><Button
                        v-if="cover"
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="removeMedia(cover)"
                        >Remove cover</Button
                    >
                </div>
            </form>
            <form
                class="space-y-3 border-t pt-5"
                @submit.prevent="uploadScreenshots"
            >
                <Label for="screenshot-files">Screenshots</Label>
                <Input
                    :key="screenshots.map((item) => item.id).join('-')"
                    id="screenshot-files"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    :disabled="busy"
                    @change="screenshotForm.files = selected($event)"
                />
                <Label for="replace-screenshot">Add or replace</Label>
                <select
                    id="replace-screenshot"
                    v-model="screenshotForm.replace_id"
                    class="h-9 w-full rounded-md border bg-background px-3"
                    :disabled="busy"
                >
                    <option :value="null">Add new screenshots</option>
                    <option
                        v-for="item in screenshots"
                        :key="item.id"
                        :value="item.id"
                    >
                        Replace {{ item.original_name }}
                    </option>
                </select>
                <p class="text-sm text-muted-foreground">
                    At least 2 for submission; max {{ limits.screenshots_max }}.
                    Each max {{ limits.screenshot_max_kb / 1024 }} MB, minimum
                    320 × 180. Replacement accepts one image.
                </p>
                <progress
                    v-if="screenshotForm.progress"
                    :value="screenshotForm.progress.percentage"
                    max="100"
                    aria-label="Screenshot upload progress"
                />
                <Button :disabled="busy || !screenshotForm.files.length"
                    >Upload screenshots</Button
                >
            </form>
            <div class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="item in screenshots"
                    :key="item.id"
                    class="space-y-3 rounded-lg border p-3"
                >
                    <img
                        v-if="item.url"
                        :src="item.url"
                        :alt="item.alt_text || 'Screenshot'"
                        class="h-40 w-full object-contain"
                    />
                    <p class="text-sm">
                        {{ item.original_name }} · {{ size(item.size_bytes) }}
                    </p>
                    <Label :for="`alt-${item.id}`">Alt text</Label
                    ><Input
                        :id="`alt-${item.id}`"
                        :model-value="item.alt_text ?? ''"
                        maxlength="500"
                        :disabled="busy"
                        @change="metadata(item, $event, 'alt_text')"
                    />
                    <Label :for="`order-${item.id}`">Sort order</Label
                    ><Input
                        :id="`order-${item.id}`"
                        :model-value="item.sort_order"
                        type="number"
                        min="0"
                        max="1000000"
                        :disabled="busy"
                        @change="metadata(item, $event, 'sort_order')"
                    />
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="removeMedia(item)"
                        >Remove screenshot</Button
                    >
                </article>
            </div>
        </section>
        <section class="space-y-5 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Prepared Release</h2>
            <form class="grid gap-3" @submit.prevent="saveRelease">
                <Label for="release-version">Release version</Label
                ><Input
                    id="release-version"
                    v-model="releaseForm.version"
                    placeholder="1.0.0"
                    maxlength="50"
                    :disabled="
                        busy || (prepared && prepared.status !== 'draft')
                    "
                />
                <Label for="release-changelog">Changelog</Label
                ><textarea
                    id="release-changelog"
                    v-model="releaseForm.changelog"
                    rows="4"
                    maxlength="20000"
                    class="rounded-md border bg-background p-3"
                    :disabled="busy"
                />
                <Label for="release-notes">Release notes (optional)</Label
                ><textarea
                    id="release-notes"
                    v-model="releaseForm.release_notes"
                    rows="3"
                    maxlength="20000"
                    class="rounded-md border bg-background p-3"
                    :disabled="busy"
                />
                <Button
                    :disabled="
                        busy || (prepared && prepared.status !== 'draft')
                    "
                    >{{ prepared ? 'Save release' : 'Create release' }}</Button
                >
            </form>
            <template v-if="prepared && prepared.status === 'draft'">
                <form
                    class="space-y-3 border-t pt-5"
                    @submit.prevent="uploadArtifact(false)"
                >
                    <Label for="package-file"
                        >Private source package (ZIP)</Label
                    ><Input
                        :key="
                            prepared.artifacts.find(
                                (file) => file.type === 'source_package',
                            )?.id
                        "
                        id="package-file"
                        type="file"
                        accept=".zip"
                        :disabled="busy"
                        @change="packageForm.file = selected($event)[0] ?? null"
                    />
                    <p class="text-sm text-muted-foreground">
                        Max {{ limits.package_max_kb / 1024 }} MB. The package
                        is stored privately.
                    </p>
                    <progress
                        v-if="packageForm.progress"
                        :value="packageForm.progress.percentage"
                        max="100"
                        aria-label="Package upload progress"
                    />
                    <Button :disabled="busy || !packageForm.file"
                        >Upload / replace package</Button
                    >
                </form>
                <form
                    class="space-y-3 border-t pt-5"
                    @submit.prevent="uploadArtifact(true)"
                >
                    <Label for="documentation-file"
                        >Documentation (PDF or ZIP, optional)</Label
                    ><Input
                        :key="
                            prepared.artifacts.find(
                                (file) => file.type === 'documentation',
                            )?.id
                        "
                        id="documentation-file"
                        type="file"
                        accept=".pdf,.zip"
                        :disabled="busy"
                        @change="docForm.file = selected($event)[0] ?? null"
                    />
                    <p class="text-sm text-muted-foreground">
                        Max {{ limits.documentation_max_kb / 1024 }} MB.
                    </p>
                    <progress
                        v-if="docForm.progress"
                        :value="docForm.progress.percentage"
                        max="100"
                        aria-label="Documentation upload progress"
                    />
                    <Button :disabled="busy || !docForm.file"
                        >Upload / replace documentation</Button
                    >
                </form>
                <article
                    v-for="file in prepared.artifacts"
                    :key="file.id"
                    class="space-y-2 rounded-md border p-3"
                >
                    <p>
                        {{ file.original_name }} · {{ size(file.size_bytes) }}
                    </p>
                    <p class="font-mono text-xs break-all">
                        SHA-256: {{ file.checksum }}
                    </p>
                    <a
                        :href="
                            artifactRoutes.download.url([
                                product.id,
                                prepared.id,
                                file.id,
                            ])
                        "
                        class="mr-3 text-sm underline"
                        >Download file</a
                    ><Button
                        variant="outline"
                        :disabled="busy"
                        @click="removeArtifact(file.id)"
                        >Remove
                        {{
                            file.type === 'source_package'
                                ? 'package'
                                : 'documentation'
                        }}</Button
                    >
                </article>
            </template>
        </section>
    </div>
</template>
