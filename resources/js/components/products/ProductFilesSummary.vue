<script setup lang="ts">
import { computed } from 'vue';
import type { ProductRecord } from '@/types/product';
import * as artifacts from '@/routes/products/files/artifacts';
import * as reviewedArtifacts from '@/routes/admin/product-reviews/artifacts';
const props = defineProps<{ product: ProductRecord; submissionId?: number }>();
const media = computed(() => props.product.media ?? []);
const cover = computed(() => media.value.find((item) => item.type === 'cover'));
const screenshots = computed(() =>
    media.value.filter((item) => item.type === 'screenshot'),
);
const size = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;
function download(release: number, artifact: number) {
    return props.submissionId
        ? reviewedArtifacts.download.url([props.submissionId, artifact])
        : artifacts.download.url([props.product.id, release, artifact]);
}
</script>
<template>
    <section class="space-y-5 rounded-lg border p-5">
        <h2 class="text-lg font-semibold">Product Media</h2>
        <img
            v-if="cover?.url"
            :src="cover.url"
            :alt="cover.alt_text || `${product.name} cover`"
            class="max-h-80 w-full rounded-lg object-contain"
        />
        <p v-else class="text-sm text-muted-foreground">No cover uploaded.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <figure
                v-for="item in screenshots"
                :key="item.id"
                class="space-y-2"
            >
                <a
                    v-if="item.url"
                    :href="item.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    ><img
                        :src="item.url"
                        :alt="item.alt_text || `${product.name} screenshot`"
                        loading="lazy"
                        class="h-48 w-full rounded-md border object-contain"
                /></a>
                <figcaption class="text-sm">
                    {{ item.alt_text || item.original_name }}
                </figcaption>
            </figure>
        </div>
        <a
            v-if="product.preview_video_url"
            :href="product.preview_video_url"
            target="_blank"
            rel="noopener noreferrer"
            class="underline"
            >Open preview video</a
        >
    </section>
    <section class="space-y-5 rounded-lg border p-5">
        <h2 class="text-lg font-semibold">Releases / Files</h2>
        <p
            v-if="!product.releases?.length"
            class="text-sm text-muted-foreground"
        >
            No release prepared yet.
        </p>
        <article
            v-for="release in product.releases ?? []"
            :key="release.id"
            class="space-y-3 rounded-lg border p-4"
        >
            <h3 class="font-semibold">Version {{ release.version }}</h3>
            <p class="whitespace-pre-wrap">{{ release.changelog }}</p>
            <p v-if="release.release_notes" class="text-sm whitespace-pre-wrap">
                {{ release.release_notes }}
            </p>
            <div
                v-for="file in release.artifacts"
                :key="file.id"
                class="space-y-2 rounded-md bg-muted/40 p-3"
            >
                <h4 class="font-medium">
                    {{
                        file.type === 'source_package'
                            ? 'Source package'
                            : 'Documentation'
                    }}
                </h4>
                <p>{{ file.original_name }} · {{ size(file.size_bytes) }}</p>
                <p class="font-mono text-xs break-all">
                    SHA-256: {{ file.checksum }}
                </p>
                <a
                    :href="download(release.id, file.id)"
                    class="text-sm underline"
                    >Download
                    {{
                        file.type === 'source_package'
                            ? 'package'
                            : 'documentation'
                    }}</a
                >
            </div>
        </article>
    </section>
</template>
