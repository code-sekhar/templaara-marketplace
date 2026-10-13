<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ProductCard from '@/components/marketplace/ProductCard.vue';
import SeoHead from '@/components/marketplace/SeoHead.vue';
import type { PublicAuthor } from '@/types/author-professional';
import type { ProductCardData, Seo } from '@/types/marketplace';
import type { Pagination } from '@/types/authors';
defineProps<{
    author: PublicAuthor;
    products: Pagination<ProductCardData>;
    seo: Seo;
}>();
</script>
<template>
    <SeoHead :seo="seo" />
    <div class="space-y-10">
        <header class="overflow-hidden rounded-2xl border">
            <img
                v-if="author.cover"
                :src="author.cover.url"
                :alt="author.name + ' cover'"
                class="h-40 w-full object-cover sm:h-56"
            />
            <div v-else class="h-16 bg-muted/60" />
            <div class="space-y-5 p-6 sm:p-9">
                <div class="flex flex-wrap items-start gap-5">
                    <img
                        v-if="author.avatar"
                        :src="author.avatar.url"
                        :alt="author.name + ' profile image'"
                        class="h-20 w-20 rounded-2xl border object-cover"
                    /><span
                        v-else
                        class="flex h-20 w-20 items-center justify-center rounded-2xl border bg-muted text-3xl font-semibold"
                        aria-hidden="true"
                        >{{ author.name.slice(0, 1) }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{
                                author.author_type === 'company'
                                    ? 'Software company'
                                    : 'Software professional'
                            }}
                        </p>
                        <h1
                            class="mt-2 text-3xl font-semibold tracking-tight break-words sm:text-4xl"
                        >
                            {{ author.name }}
                        </h1>
                        <p
                            v-if="author.headline"
                            class="mt-3 text-lg break-words text-muted-foreground"
                        >
                            {{ author.headline }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <span
                        v-if="author.verified"
                        class="rounded-full border px-3 py-1 font-medium"
                        >Verified Author</span
                    ><span
                        v-if="author.years_of_experience !== null"
                        class="rounded-full bg-brand-blue-soft px-3 py-1 text-primary"
                        >{{ author.years_of_experience }}+ years
                        experience</span
                    ><span
                        class="rounded-full bg-brand-blue-soft px-3 py-1 text-primary"
                        >{{ author.published_products }} published software
                        {{
                            author.published_products === 1
                                ? 'product'
                                : 'products'
                        }}</span
                    ><span
                        v-if="author.country_code"
                        class="rounded-full bg-brand-blue-soft px-3 py-1 text-primary"
                        >{{
                            [
                                author.city,
                                author.state_region,
                                author.country_code,
                            ]
                                .filter(Boolean)
                                .join(', ')
                        }}</span
                    >
                </div>
                <p
                    v-if="author.verified"
                    class="max-w-2xl text-xs leading-relaxed text-muted-foreground"
                >
                    Verified means approved through Templaara's author
                    application process. It does not imply government
                    certification, a security audit or a product guarantee.
                </p>
            </div>
        </header>
        <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_280px]">
            <main class="min-w-0 space-y-10">
                <section>
                    <h2 class="text-2xl font-semibold">
                        About {{ author.name }}
                    </h2>
                    <p
                        class="mt-4 leading-relaxed break-words whitespace-pre-wrap text-muted-foreground"
                    >
                        {{
                            author.bio ||
                            'This author is completing their professional introduction.'
                        }}
                    </p>
                </section>
                <section v-if="author.expertise.length">
                    <h2 class="text-xl font-semibold">Business expertise</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            v-for="item in author.expertise"
                            :key="item.slug"
                            class="rounded-lg border px-3 py-2 text-sm"
                            >{{ item.name }}</span
                        >
                    </div>
                </section>
                <section>
                    <h2 class="text-2xl font-semibold">Selected work</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Professional projects shared by the author. Client
                        relationships and portfolio ownership are not
                        independently verified.
                    </p>
                    <div
                        v-if="author.portfolio.length"
                        class="mt-5 grid gap-5 sm:grid-cols-2"
                    >
                        <article
                            v-for="item in author.portfolio"
                            :key="item.public_uuid"
                            class="min-w-0 overflow-hidden rounded-2xl border"
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
                            <div class="space-y-3 p-5">
                                <p
                                    class="text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ item.project_type.replace('_', ' ') }}
                                    {{
                                        item.completion_year
                                            ? '· ' + item.completion_year
                                            : ''
                                    }}
                                </p>
                                <h3 class="text-lg font-semibold break-words">
                                    {{ item.title }}
                                </h3>
                                <p
                                    class="text-sm leading-relaxed break-words text-muted-foreground"
                                >
                                    {{ item.short_description }}
                                </p>
                                <p
                                    v-if="item.description"
                                    class="text-sm break-words whitespace-pre-wrap"
                                >
                                    {{ item.description }}
                                </p>
                                <p
                                    v-if="item.client_name"
                                    class="text-xs break-words text-muted-foreground"
                                >
                                    Client/company:
                                    {{ item.client_name }} (author provided)
                                </p>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <a
                                        v-for="(url, label) in {
                                            Project: item.project_url,
                                            'Case study': item.case_study_url,
                                            Repository: item.repository_url,
                                        }"
                                        v-show="url"
                                        :key="label"
                                        :href="url ?? undefined"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline underline-offset-4"
                                        >{{ label }} ↗</a
                                    >
                                </div>
                            </div>
                        </article>
                    </div>
                    <p
                        v-else
                        class="mt-5 rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
                    >
                        No public portfolio projects shared yet.
                    </p>
                </section>
            </main>
            <aside class="min-w-0 space-y-5">
                <section class="rounded-2xl border p-5">
                    <h2 class="font-semibold">Professional links</h2>
                    <div class="mt-4 grid gap-3">
                        <a
                            v-for="(url, label) in author.links"
                            :key="label"
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm break-words underline underline-offset-4"
                            >{{
                                label.replace('_url', '').replace('_', ' ')
                            }}
                            ↗</a
                        >
                        <p
                            v-if="!Object.keys(author.links).length"
                            class="text-sm text-muted-foreground"
                        >
                            No links shared.
                        </p>
                    </div>
                </section>
                <section
                    v-if="
                        author.languages.length || author.support_availability
                    "
                    class="rounded-2xl border p-5"
                >
                    <h2 class="font-semibold">Communication</h2>
                    <p class="mt-3 text-sm">
                        {{ author.languages.join(', ') }}
                    </p>
                    <p class="mt-3 text-sm break-words text-muted-foreground">
                        {{ author.support_availability }}
                    </p>
                    <p
                        class="mt-3 text-xs leading-relaxed text-muted-foreground"
                    >
                        Availability is author-provided guidance. Your purchased
                        product's support terms remain authoritative.
                    </p>
                </section>
                <section
                    v-if="author.technologies.length"
                    class="rounded-2xl border p-5"
                >
                    <h2 class="font-semibold">Technical experience</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="item in author.technologies"
                            :key="item.slug"
                            class="rounded-md bg-muted px-2 py-1 text-xs"
                            >{{ item.name }}</span
                        >
                    </div>
                </section>
            </aside>
        </div>
        <section>
            <h2 class="text-2xl font-semibold">
                Business software by {{ author.name }}
            </h2>
            <div
                v-if="products.data.length"
                class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
            >
                <ProductCard
                    v-for="product in products.data"
                    :key="product.slug"
                    :product="product"
                />
            </div>
            <p
                v-else
                class="mt-5 rounded-xl border border-dashed p-7 text-muted-foreground"
            >
                No public software available yet.
            </p>
            <nav
                v-if="products.last_page > 1"
                aria-label="Product pagination"
                class="mt-6 flex flex-wrap gap-3"
            >
                <Link
                    v-if="products.prev_page_url"
                    :href="products.prev_page_url"
                    class="rounded-lg border px-4 py-2"
                    >Previous</Link
                ><span class="px-3 py-2"
                    >Page {{ products.current_page }} of
                    {{ products.last_page }}</span
                ><Link
                    v-if="products.next_page_url"
                    :href="products.next_page_url"
                    class="rounded-lg border px-4 py-2"
                    >Next</Link
                >
            </nav>
        </section>
    </div>
</template>
