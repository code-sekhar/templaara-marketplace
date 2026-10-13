<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatPrice } from '@/lib/marketplace';
import { show as categoryShow } from '@/routes/marketplace/categories';
import { show } from '@/routes/marketplace/products';
import type { ProductCardData } from '@/types/marketplace';
withDefaults(
    defineProps<{ product: ProductCardData; mode?: 'grid' | 'list' }>(),
    { mode: 'grid' },
);
</script>
<template>
    <article
        :class="
            mode === 'list'
                ? 'product-card-list flex-col md:flex-row'
                : 'flex-col'
        "
        class="product-card group flex h-full overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow hover:shadow-md"
    >
        <Link
            :href="show(product.slug)"
            :aria-label="`View ${product.name}`"
            class="product-preview relative block"
        >
            <img
                :src="product.cover.url"
                :alt="product.cover.alt"
                loading="lazy"
                class="aspect-video w-full bg-muted object-contain"
            />
            <span
                v-if="product.featured"
                class="absolute top-4 left-4 rounded-lg border border-orange-100 bg-white/95 px-2.5 py-1 text-xs font-semibold text-brand-coral shadow-sm"
                >Featured</span
            >
        </Link>
        <div class="product-card-content flex grow flex-col gap-2.5 p-5">
            <div v-if="product.official" class="flex flex-wrap gap-2 text-xs">
                <span
                    v-if="product.official"
                    class="rounded-md bg-brand-blue-soft px-2 py-1 font-medium text-brand-blue"
                    >Templaara Official</span
                >
            </div>
            <Link
                :href="categoryShow(product.category.slug)"
                class="text-xs font-medium text-muted-foreground hover:underline"
                >{{ product.category.name }}</Link
            >
            <h3 class="text-lg leading-6 font-semibold">
                <Link
                    :href="show(product.slug)"
                    class="line-clamp-2 hover:underline"
                    :title="product.name"
                    >{{ product.name }}</Link
                >
            </h3>
            <p
                v-if="product.rating.count > 0"
                class="text-sm"
                :aria-label="`${product.rating.average} out of 5 from ${product.rating.count} verified reviews`"
            >
                ★ {{ product.rating.average }} ({{ product.rating.count }})
            </p>
            <p class="line-clamp-2 text-sm text-muted-foreground">
                {{ product.short_description }}
            </p>
            <p
                v-if="product.industries.length"
                class="text-xs text-muted-foreground"
            >
                For
                {{
                    product.industries
                        .slice(0, 2)
                        .map((industry) => industry.name)
                        .join(' · ')
                }}
            </p>
            <div
                class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t pt-4"
            >
                <p class="text-sm">
                    <span class="block text-xs text-muted-foreground"
                        >Starting at</span
                    ><strong>{{
                        formatPrice(product.starting_price, product.currency)
                    }}</strong>
                </p>
                <Link
                    :href="show(product.slug)"
                    class="rounded-lg bg-brand-blue-soft px-3 py-2 text-sm font-semibold text-primary hover:bg-primary hover:text-primary-foreground"
                    >View Details</Link
                >
            </div>
        </div>
    </article>
</template>
