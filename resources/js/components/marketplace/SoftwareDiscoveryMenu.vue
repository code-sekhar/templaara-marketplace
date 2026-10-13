<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ArrowRight } from '@lucide/vue';
import {
    PopoverRoot,
    PopoverAnchor,
    PopoverTrigger,
    PopoverPortal,
    PopoverContent,
} from 'reka-ui';
import { categoryPresentation } from '@/lib/discovery';
import { show } from '@/routes/marketplace/categories';
import { index } from '@/routes/marketplace/products';

type Category = { name: string; slug: string; description: string | null };
const page = usePage();
const open = ref(false);
const categories = computed(
    () => (page.props.marketplaceNavigation ?? []) as Category[],
);
const definitions = [
    {
        title: 'Business operations',
        slugs: [
            'pos-inventory',
            'hr-payroll',
            'accounting-finance',
            'field-service-management',
        ],
    },
    {
        title: 'Customer & growth',
        slugs: ['crm', 'ecommerce', 'customer-support'],
    },
    {
        title: 'Work & planning',
        slugs: [
            'booking-reservation',
            'project-management',
            'business-analytics',
        ],
    },
];
const groups = computed(() => {
    const known = definitions.flatMap((group) => group.slugs);
    return [
        ...definitions.map((group) => ({
            title: group.title,
            items: categories.value.filter((category) =>
                group.slugs.includes(category.slug),
            ),
        })),
        {
            title: 'More business software',
            items: categories.value.filter(
                (category) => !known.includes(category.slug),
            ),
        },
    ].filter((group) => group.items.length);
});
watch(
    () => page.url,
    () => {
        open.value = false;
    },
);
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverAnchor
            aria-hidden="true"
            class="pointer-events-none absolute bottom-0 left-1/2 h-0 w-0"
        />
        <PopoverTrigger
            class="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 hover:bg-muted focus-visible:outline-2 focus-visible:outline-primary"
        >
            Browse Software <ChevronDown class="size-3.5" aria-hidden="true" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                aria-label="Browse business software"
                :side-offset="16"
                :collision-padding="16"
                align="center"
                class="software-mega-menu z-50 max-h-[calc(100dvh-110px)] w-[min(1100px,calc(100vw-32px))] overflow-y-auto rounded-3xl border bg-white p-6 text-foreground shadow-2xl outline-none"
            >
                <div class="grid grid-cols-[minmax(0,1fr)_220px] gap-6">
                    <div>
                        <div
                            class="mb-5 flex items-center justify-between gap-3 border-b pb-4"
                        >
                            <span class="text-sm font-semibold"
                                >Find the right tool for your business</span
                            >
                            <Link
                                :href="index()"
                                class="shrink-0 text-xs font-semibold text-primary"
                                @click="open = false"
                                >All software →</Link
                            >
                        </div>
                        <div class="grid grid-cols-3 gap-x-5 gap-y-6">
                            <section
                                v-for="group in groups"
                                :key="group.title"
                                class="min-w-0"
                                :class="{
                                    'col-span-3':
                                        group.title ===
                                        'More business software',
                                }"
                            >
                                <h2
                                    class="mb-3 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                                >
                                    {{ group.title }}
                                </h2>
                                <div
                                    :class="
                                        group.title === 'More business software'
                                            ? 'grid grid-cols-3 gap-x-4'
                                            : ''
                                    "
                                >
                                    <Link
                                        v-for="category in group.items"
                                        :key="category.slug"
                                        :href="show(category.slug)"
                                        class="mb-1 flex gap-3 rounded-xl p-2.5 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-primary"
                                        @click="open = false"
                                    >
                                        <span
                                            class="icon-tile !size-9 shrink-0"
                                            :data-accent="
                                                categoryPresentation(
                                                    category.slug,
                                                ).accent
                                            "
                                            ><component
                                                :is="
                                                    categoryPresentation(
                                                        category.slug,
                                                    ).icon
                                                "
                                                class="size-4"
                                                aria-hidden="true"
                                        /></span>
                                        <span class="min-w-0"
                                            ><span
                                                class="block text-xs leading-5 font-semibold"
                                                >{{ category.name }}</span
                                            ><span
                                                class="mt-0.5 line-clamp-2 block text-[11px] leading-4 text-muted-foreground"
                                                >{{
                                                    category.description ||
                                                    categoryPresentation(
                                                        category.slug,
                                                    ).description ||
                                                    'Explore tools built for your business.'
                                                }}</span
                                            ></span
                                        >
                                    </Link>
                                </div>
                            </section>
                        </div>
                    </div>
                    <aside
                        class="flex flex-col justify-center rounded-2xl bg-brand-blue-soft p-5"
                    >
                        <img
                            src="/illustrations/author-studio.svg"
                            alt=""
                            class="mb-5 w-full"
                            width="300"
                            height="185"
                        />
                        <p
                            class="text-[10px] font-semibold tracking-widest text-primary uppercase"
                        >
                            Sell on Templaara
                        </p>
                        <h2 class="mt-3 text-lg leading-snug font-semibold">
                            Build software for real businesses.
                        </h2>
                        <p class="mt-3 text-xs leading-5 text-muted-foreground">
                            Present your business tools with clear features,
                            demos and package terms.
                        </p>
                        <Link
                            href="/author/onboarding"
                            class="mt-5 inline-flex items-center gap-2 text-xs font-semibold text-primary"
                            @click="open = false"
                            >Become an Author
                            <ArrowRight class="size-4" aria-hidden="true"
                        /></Link>
                    </aside>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
