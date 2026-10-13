<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    ArrowRight,
    Sparkles,
    Search,
    Check,
    Compass,
    Monitor,
    Layers,
    BriefcaseBusiness,
} from '@lucide/vue';
import { categoryPresentation, industryIcon } from '@/lib/discovery';
import EmptyState from '@/components/design/EmptyState.vue';
import { formatPrice } from '@/lib/marketplace';
import ProductCard from '@/components/marketplace/ProductCard.vue';
import SeoHead from '@/components/marketplace/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { show as categoryShow } from '@/routes/marketplace/categories';
import { show as industryShow } from '@/routes/marketplace/industries';
import { index, show } from '@/routes/marketplace/products';
import type { CatalogLink, ProductCardData, Seo } from '@/types/marketplace';
defineProps<{
    seo: Seo;
    categories: CatalogLink[];
    industries: CatalogLink[];
    featured: ProductCardData[];
    recent: ProductCardData[];
}>();
const search = ref('');
</script>
<template>
    <SeoHead :seo="seo" />
    <div class="space-y-12 sm:space-y-16">
        <section class="premium-hero grid items-center gap-8 lg:grid-cols-2">
            <div>
                <p class="hero-badge">
                    <Sparkles class="size-3.5" aria-hidden="true" />THE BUSINESS
                    SOFTWARE MARKETPLACE
                </p>
                <h1 class="hero-title mt-5 max-w-2xl">
                    Software built for
                    <span class="text-primary">real businesses.</span>
                </h1>
                <p
                    class="mt-6 max-w-xl text-lg leading-relaxed text-muted-foreground"
                >
                    Better tools for the work you do. Discover ready business
                    software, compare clear packages and find your next step
                    forward.
                </p>
                <form
                    class="premium-search mt-6 max-w-lg"
                    role="search"
                    @submit.prevent="router.get(index.url(), { search })"
                >
                    <Search
                        aria-hidden="true"
                        class="ml-3 hidden size-5 shrink-0 text-primary sm:block"
                    /><label for="marketplace-search" class="sr-only"
                        >What software does your business need?</label
                    ><Input
                        id="marketplace-search"
                        v-model="search"
                        type="search"
                        maxlength="100"
                        placeholder="Inventory, payroll, CRM…"
                        class="border-0 bg-transparent shadow-none"
                    /><Button type="submit" class="shrink-0"
                        ><Search
                            class="size-4 sm:hidden"
                            aria-hidden="true"
                        /><span class="hidden sm:inline">Search Software</span
                        ><span class="sr-only sm:hidden"
                            >Search Software</span
                        ></Button
                    >
                </form>
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <Button as-child size="lg"
                        ><Link :href="index()"
                            >Browse Software<ArrowRight
                                class="size-4"
                                aria-hidden="true" /></Link></Button
                    ><Button as-child variant="outline" size="lg"
                        ><Link href="/author/onboarding"
                            >Become an Author</Link
                        ></Button
                    >
                </div>
                <div
                    class="mt-7 flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted-foreground"
                >
                    <span
                        v-for="text in [
                            'Business-first discovery',
                            'Live product demos',
                            'Clear package terms',
                        ]"
                        :key="text"
                        class="inline-flex items-center gap-1.5"
                        ><Check
                            class="size-3.5 text-success"
                            aria-hidden="true"
                        />{{ text }}</span
                    >
                </div>
            </div>
            <aside
                class="hero-visual hero-illustration relative"
                aria-label="Decorative business software illustration"
            >
                <img
                    src="/illustrations/saas-workspace.svg"
                    alt="Illustrative software panels for inventory, planning and connected teams; not marketplace statistics"
                    width="680"
                    height="480"
                    fetchpriority="high"
                    class="w-full"
                />
                <Link
                    v-for="(item, i) in featured.slice(0, 2)"
                    :key="item.slug"
                    :href="`/products/${item.slug}`"
                    class="hero-product-preview hidden lg:flex"
                    :class="
                        i === 0 ? 'hero-preview-first' : 'hero-preview-second'
                    "
                >
                    <img
                        :src="item.cover.url"
                        :alt="item.cover.alt"
                        class="h-14 w-20 rounded-lg border object-cover"
                    />
                    <div class="min-w-0">
                        <span
                            class="text-[10px] font-semibold text-brand-coral"
                            >{{
                                item.name.endsWith('(Demo)')
                                    ? 'Featured demo'
                                    : 'Featured software'
                            }}</span
                        >
                        <p class="line-clamp-1 text-xs font-semibold">
                            {{ item.name }}
                        </p>
                        <span class="text-[10px] text-muted-foreground">{{
                            item.category.name
                        }}</span>
                    </div>
                </Link>
                <p
                    class="mt-2 text-center text-[10px] tracking-wide text-muted-foreground"
                >
                    Illustrative workspace · discover real software below
                </p>
            </aside>
        </section>
        <section id="categories" class="discovery-section space-y-7">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow mb-3">FIND YOUR SOLUTION</p>
                    <h2
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        What does your business need?
                    </h2>
                    <p class="mt-3 text-sm text-muted-foreground">
                        Start with the work you want to make easier.
                    </p>
                </div>
                <Link
                    :href="index()"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-primary"
                    >Explore software<ArrowRight
                        class="size-4"
                        aria-hidden="true"
                /></Link>
            </div>
            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
            >
                <Link
                    v-for="category in categories.slice(0, 12)"
                    :key="category.slug"
                    :href="categoryShow(category.slug)"
                    class="category-card category-discovery-card"
                    :data-accent="categoryPresentation(category.slug).accent"
                    ><span class="icon-tile shrink-0"
                        ><component
                            :is="categoryPresentation(category.slug).icon"
                            class="size-6"
                            aria-hidden="true"
                    /></span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold">
                            {{ category.name }}
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{
                                categoryPresentation(category.slug)
                                    .description ||
                                'Explore tools for everyday business needs'
                            }}
                        </p>
                        <span
                            class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-primary"
                            >Explore
                            <ArrowRight class="size-3.5" aria-hidden="true"
                        /></span></div
                ></Link>
            </div>
            <EmptyState
                v-if="!categories.length"
                title="Business categories are being prepared"
                description="Explore the marketplace as new software becomes available."
            />
        </section>

        <section v-if="featured.length" class="space-y-6">
            <div>
                <p class="eyebrow mb-3">IN THE SPOTLIGHT</p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Featured software
                </h2>
            </div>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <ProductCard
                    v-for="product in featured"
                    :key="product.slug"
                    :product="product"
                />
            </div>
        </section>
        <section class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="eyebrow mb-3">FRESH BUSINESS TOOLS</p>
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Recently published
                    </h2>
                </div>
                <Link
                    :href="index({ query: { sort: 'newest' } })"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-primary"
                    >Browse all<ArrowRight class="size-4" aria-hidden="true"
                /></Link>
            </div>
            <div
                v-if="recent.length"
                class="grid gap-6 md:grid-cols-2 lg:grid-cols-3"
            >
                <ProductCard
                    v-for="product in recent"
                    :key="product.slug"
                    :product="product"
                />
            </div>
            <EmptyState
                v-else
                title="Your next business tool is on its way"
                description="Discover the business categories while new software is prepared."
                ><Link
                    href="#categories"
                    class="text-sm font-semibold text-primary"
                    >Browse categories →</Link
                ></EmptyState
            >
        </section>
        <section id="industries" class="industry-section">
            <div class="mb-6">
                <p class="eyebrow mb-3">MADE FOR THE WAY YOU WORK</p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Software for your industry
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Find solutions that understand your business context.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <Link
                    v-for="industry in industries.slice(0, 12)"
                    :key="industry.slug"
                    :href="industryShow(industry.slug)"
                    class="inline-flex items-center gap-2.5 rounded-xl border border-white bg-white/90 px-4 py-3 text-sm font-medium shadow-sm transition-colors hover:border-primary/30"
                    ><component
                        :is="industryIcon(industry.slug)"
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />{{ industry.name }}</Link
                >
            </div>
        </section>
        <section class="decision-section">
            <p class="text-xs font-semibold tracking-widest text-blue-100">
                CHOOSE WITH CONFIDENCE
            </p>
            <h2 class="mt-3 text-2xl font-semibold sm:text-3xl">
                A clearer way to find business software.
            </h2>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="[title, text, icon] in [
                        [
                            'Business value first',
                            'Understand the problem each product solves.',
                            BriefcaseBusiness,
                        ],
                        [
                            'Explore live demos',
                            'Try the experience before choosing a solution.',
                            Monitor,
                        ],
                        [
                            'Transparent packages',
                            'See pricing, license scope and support terms.',
                            Layers,
                        ],
                        [
                            'Clear features',
                            'Find tools suited to your daily operations.',
                            Check,
                        ],
                    ]"
                    :key="String(title)"
                >
                    <component
                        :is="icon"
                        class="mb-4 size-5 text-blue-100"
                        aria-hidden="true"
                    />
                    <h3 class="font-semibold">{{ title }}</h3>
                    <p class="mt-2 text-sm">{{ text }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
