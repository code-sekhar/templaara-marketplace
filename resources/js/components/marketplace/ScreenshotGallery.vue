<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PublicImage } from '@/types/marketplace';
const props = defineProps<{
    images: PublicImage[];
    name: string;
    hero?: boolean;
}>();
const open = ref(false);
const viewerTrigger = ref<HTMLButtonElement | null>(null);
function restoreFocus(event: Event) {
    if (props.hero) {
        event.preventDefault();
        viewerTrigger.value?.focus();
    }
}
const selected = ref(0);
function move(offset: number) {
    selected.value =
        (selected.value + offset + props.images.length) % props.images.length;
}
function navigate(event: KeyboardEvent) {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        move(event.key === 'ArrowLeft' ? -1 : 1);
    }
}
</script>
<template>
    <section
        aria-labelledby="screenshots-title"
        class="space-y-5"
        :aria-label="hero ? `${name} gallery` : undefined"
    >
        <h2 v-if="!hero" id="screenshots-title" class="text-2xl font-semibold">
            See it in action
        </h2>
        <button
            ref="viewerTrigger"
            v-if="hero && images[selected]"
            type="button"
            class="product-detail-cover block w-full overflow-hidden rounded-2xl border bg-white focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Open image viewer"
            @click="open = true"
        >
            <img
                :src="images[selected].url"
                :alt="images[selected].alt"
                class="aspect-video w-full object-contain"
                fetchpriority="high"
            />
        </button>
        <div
            :class="
                hero
                    ? 'grid grid-cols-3 gap-3'
                    : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3'
            "
        >
            <button
                v-for="(image, i) in images"
                :key="image.url"
                type="button"
                class="overflow-hidden rounded-xl border focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="`Enlarge screenshot ${i + 1}: ${image.alt}`"
                :aria-pressed="hero ? selected === i : undefined"
                :class="{ 'ring-2 ring-primary': hero && selected === i }"
                @click="
                    selected = i;
                    if (!hero) open = true;
                "
            >
                <img
                    :src="image.url"
                    :alt="image.alt"
                    loading="lazy"
                    class="aspect-video w-full bg-muted object-contain"
                />
            </button>
        </div>
        <Dialog v-model:open="open">
            <DialogContent
                class="sm:max-w-5xl"
                @keydown="navigate"
                @close-auto-focus="restoreFocus"
            >
                <DialogTitle>{{ name }} screenshots</DialogTitle>
                <DialogDescription
                    >Screenshot {{ selected + 1 }} of {{ images.length }}. Use
                    the arrow keys to browse.</DialogDescription
                >
                <div class="space-y-4">
                    <img
                        v-if="images[selected]"
                        :src="images[selected].url"
                        :alt="images[selected].alt"
                        class="max-h-[65vh] w-full object-contain"
                    />
                    <div class="flex justify-between">
                        <Button variant="outline" @click="move(-1)"
                            >Previous</Button
                        ><Button variant="outline" @click="move(1)"
                            >Next</Button
                        >
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
