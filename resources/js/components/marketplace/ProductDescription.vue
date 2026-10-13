<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ text: string }>();
// Product content is plain text. Preserve paragraphs/list structure without HTML injection.
const blocks = computed(() =>
    props.text
        .trim()
        .split(/\n\s*\n/)
        .filter(Boolean)
        .map((text) => ({
            text,
            list: text
                .split('\n')
                .every((line) => /^[-*]\s+/.test(line.trim())),
        })),
);
</script>
<template>
    <div
        class="product-description space-y-5 text-base leading-8 text-foreground/85"
    >
        <template v-for="(block, index) in blocks" :key="index">
            <ul v-if="block.list" class="list-disc space-y-2 pl-5">
                <li v-for="(line, i) in block.text.split('\n')" :key="i">
                    {{ line.trim().replace(/^[-*]\s+/, '') }}
                </li>
            </ul>
            <p v-else class="whitespace-pre-line">{{ block.text }}</p>
        </template>
        <p v-if="!blocks.length" class="text-muted-foreground">
            A detailed description has not been provided.
        </p>
    </div>
</template>
