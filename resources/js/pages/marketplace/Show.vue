<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useElementSize, useWindowSize } from '@vueuse/core';
import { computed, ref } from 'vue';
import AuthorIdentity from '@/components/authors/AuthorIdentity.vue';
import ProductReviews from '@/components/reviews/ProductReviews.vue';
import type { PublicReviews } from '@/types/reviews';
import ProductDescription from '@/components/marketplace/ProductDescription.vue';
import ProductCard from '@/components/marketplace/ProductCard.vue';
import ScreenshotGallery from '@/components/marketplace/ScreenshotGallery.vue';
import SeoHead from '@/components/marketplace/SeoHead.vue';
import { store as cartStore } from '@/routes/commerce/cart';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/marketplace';
import { show as categoryShow } from '@/routes/marketplace/categories';
import { show as industryShow } from '@/routes/marketplace/industries';
import type { ProductCardData, PublicProduct, Seo } from '@/types/marketplace';
const props = defineProps<{
    reviews: PublicReviews;
    support_entry: { url: string | null };
    seo: Seo;
    product: PublicProduct;
    related: ProductCardData[];
}>();
const page = usePage();
const cartForm = useForm({ product_slug: props.product.slug, offer_id: 0 });
function addToCart(offer: number) {
    cartForm.product_slug = props.product.slug;
    cartForm.offer_id = offer;
    cartForm.post(cartStore.url());
}
const selectedOffer = ref(
    props.product.offers.find((o) => o.is_default)?.offer_id ??
        props.product.offers[0]?.offer_id ??
        0,
);
const activeOffer = computed(() =>
    props.product.offers.find((o) => o.offer_id === selectedOffer.value),
);
const purchasePanel = ref<HTMLElement | null>(null);
const { height: panelHeight } = useElementSize(purchasePanel);
const { height: viewportHeight } = useWindowSize();
const copied = ref('');
async function copy(value: string, field: string) {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = `${field} copied`;
    } catch {
        copied.value = 'Select and copy the displayed credential.';
    }
}
function days(value: number | null): string {
    return value === null
        ? 'Not specified'
        : value === 0
          ? 'Not included'
          : `${value} days`;
}
</script>
<template>
    <SeoHead :seo="seo" />
    <div class="product-detail-page space-y-12">
        <div
            v-if="Object.keys(cartForm.errors).length"
            role="alert"
            class="rounded-lg border border-destructive p-4 text-destructive"
        >
            <p v-for="(error, key) in cartForm.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <nav
            aria-label="Breadcrumb"
            class="flex flex-wrap gap-2 text-sm text-muted-foreground"
        >
            <Link href="/products">Software</Link
            ><span aria-hidden="true">/</span
            ><Link :href="categoryShow(product.category.slug)">{{
                product.category.name
            }}</Link
            ><span aria-hidden="true">/</span
            ><span class="text-foreground">{{ product.name }}</span>
        </nav>
        <header class="space-y-3">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ product.name }}
            </h1>
            <p class="max-w-3xl text-lg text-muted-foreground">
                {{ product.short_description }}
            </p>
            <p v-if="product.rating.count" class="text-sm">
                ★ {{ product.rating.average }} ·
                {{ product.rating.count }} verified reviews
            </p>
            <p
                v-if="product.name.endsWith('(Demo)')"
                class="text-xs font-medium text-brand-coral"
            >
                Fictional development preview · not a functioning commercial
                application
            </p>
        </header>
        <section class="grid items-start gap-8 lg:grid-cols-[1.45fr_1fr]">
            <div class="min-w-0 space-y-6">
                <ScreenshotGallery
                    :images="[product.cover, ...product.screenshots]"
                    :name="product.name"
                    hero
                /><AuthorIdentity
                    v-if="product.author && !product.official"
                    :author="product.author"
                />
            </div>
            <div
                ref="purchasePanel"
                :class="{
                    'detail-purchase-panel': panelHeight + 112 < viewportHeight,
                }"
                class="panel purchase-panel space-y-5 p-6 sm:p-7"
            >
                <Link
                    :href="categoryShow(product.category.slug)"
                    class="text-sm text-muted-foreground hover:underline"
                    >{{ product.category.name }}</Link
                >
                <div
                    v-if="product.official"
                    class="w-fit rounded-full bg-primary/10 px-3 py-1 text-xs font-medium"
                >
                    Templaara Official
                </div>
                <h2 class="text-xl font-semibold">Choose the right package</h2>
                <p v-if="product.seller" class="text-sm text-muted-foreground">
                    By {{ product.seller }} · Version
                    {{ product.release.version }}
                </p>
                <p>
                    <span class="block text-sm text-muted-foreground">{{
                        activeOffer ? 'Selected package' : 'Starting at'
                    }}</span
                    ><strong class="text-2xl">{{
                        formatPrice(
                            activeOffer?.price ?? product.starting_price,
                            product.currency,
                        )
                    }}</strong>
                </p>
                <div class="flex flex-wrap gap-3">
                    <a
                        v-if="product.demo_url"
                        :href="product.demo_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded-lg border px-5 py-3 text-sm font-medium hover:bg-muted"
                        >View Live Demo ↗</a
                    ><a
                        href="#packages"
                        class="rounded-lg border px-5 py-3 text-sm font-medium"
                        >Explore packages</a
                    >
                </div>
                <div class="space-y-3 border-t pt-5">
                    <fieldset class="space-y-2">
                        <legend class="mb-3 text-sm font-semibold">
                            Choose your package
                        </legend>
                        <label
                            v-for="offer in product.offers"
                            :key="offer.offer_id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition-colors"
                            :class="
                                selectedOffer === offer.offer_id
                                    ? 'border-primary bg-brand-blue-soft'
                                    : 'bg-white hover:border-primary/40'
                            "
                        >
                            <input
                                v-model="selectedOffer"
                                type="radio"
                                name="hero-package"
                                :value="offer.offer_id"
                                class="mt-1 size-4 shrink-0 accent-primary"
                            />
                            <span class="min-w-0 grow"
                                ><span
                                    class="flex flex-wrap justify-between gap-2 text-sm font-semibold"
                                    ><span>{{ offer.name }}</span
                                    ><span>{{
                                        formatPrice(
                                            offer.price,
                                            product.currency,
                                        )
                                    }}</span></span
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground capitalize"
                                    >{{
                                        offer.license_type.replaceAll('_', ' ')
                                    }}
                                    license
                                    <span v-if="offer.is_default"
                                        >· Default</span
                                    ></span
                                ></span
                            >
                        </label>
                    </fieldset>
                    <Button
                        v-if="page.props.auth.user"
                        class="w-full"
                        :disabled="cartForm.processing || !selectedOffer"
                        @click="addToCart(selectedOffer)"
                        >{{
                            cartForm.processing ? 'Adding…' : 'Add to Cart'
                        }}</Button
                    ><Button v-else as-child class="w-full"
                        ><Link :href="login()"
                            >Sign in to add to Cart</Link
                        ></Button
                    >
                    <dl
                        v-if="activeOffer"
                        class="grid grid-cols-2 gap-3 rounded-xl bg-brand-blue-soft p-4 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                License scope
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{
                                    activeOffer.license_type.replaceAll(
                                        '_',
                                        ' ',
                                    )
                                }}
                            </dd>
                        </div>
                        <div v-if="activeOffer.activation_limit">
                            <dt class="text-xs text-muted-foreground">
                                Activations
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ activeOffer.activation_limit }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Support
                            </dt>
                            <dd>{{ days(activeOffer.support_period_days) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Updates
                            </dt>
                            <dd>{{ days(activeOffer.updates_period_days) }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        License, support and update terms are shown for each
                        package below. Final tax is confirmed at checkout.
                    </p>
                </div>
            </div>
        </section>
        <nav
            aria-label="Product sections"
            class="product-section-nav flex flex-wrap gap-1 border-y py-3 text-sm font-medium"
        >
            <a
                v-for="[id, label] in [
                    ['overview', 'Overview'],
                    ['features', 'Features'],
                    ['packages', 'Packages'],
                    ['technical', 'Technical & releases'],
                    ['reviews', 'Reviews'],
                ]"
                :key="id"
                :href="`#${id}`"
                class="rounded-lg px-4 py-2 text-muted-foreground hover:bg-brand-blue-soft hover:text-primary focus-visible:ring-2 focus-visible:ring-ring"
                >{{ label }}</a
            >
        </nav>
        <section
            id="overview"
            class="detail-reading-section max-w-4xl space-y-5"
        >
            <p
                class="text-xs font-semibold tracking-wider text-primary uppercase"
            >
                Software overview
            </p>
            <h2 class="text-2xl font-semibold">
                Built for your daily operations
            </h2>
            <ProductDescription :text="product.description" />
        </section>
        <section
            v-if="product.business_problem"
            class="max-w-4xl border-l-4 border-primary bg-brand-blue-soft/50 p-6 sm:p-8"
        >
            <h2 class="text-2xl font-semibold">
                What this software helps you solve
            </h2>
            <p class="mt-4 max-w-4xl leading-relaxed whitespace-pre-line">
                {{ product.business_problem }}
            </p>
        </section>
        <section class="grid gap-8 lg:grid-cols-2">
            <div>
                <h2 class="text-2xl font-semibold">Best for</h2>
                <ul class="mt-5 space-y-3">
                    <li
                        v-for="(audience, i) in product.target_audience"
                        :key="i"
                        class="border-l-2 border-primary/20 py-1 pl-4 text-sm leading-6"
                    >
                        {{ audience }}
                    </li>
                </ul>
            </div>
            <div>
                <h2 class="text-2xl font-semibold">
                    Benefits for your business
                </h2>
                <ul class="mt-5 space-y-3">
                    <li
                        v-for="(benefit, i) in product.key_benefits"
                        :key="i"
                        class="border-l-2 border-primary/20 py-1 pl-4 text-sm leading-6"
                    >
                        {{ benefit }}
                    </li>
                </ul>
            </div>
        </section>
        <section id="features" class="detail-reading-section max-w-4xl">
            <h2 class="text-2xl font-semibold">Features that get work done</h2>
            <ol class="mt-6 divide-y">
                <li
                    v-for="(feature, i) in product.features"
                    :key="i"
                    class="flex gap-5 py-5"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-blue-soft text-sm font-semibold text-primary"
                        aria-hidden="true"
                        >{{ String(i + 1).padStart(2, '0') }}</span
                    >
                    <div>
                        <h3 class="font-semibold">{{ feature.title }}</h3>
                        <p
                            v-if="feature.description"
                            class="mt-2 max-w-3xl text-sm leading-7 whitespace-pre-line text-muted-foreground"
                        >
                            {{ feature.description }}
                        </p>
                    </div>
                </li>
            </ol>
        </section>
        <section id="packages" class="detail-reading-section space-y-6">
            <div>
                <h2 class="text-2xl font-semibold">Pricing &amp; packages</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Choose the scope that suits your business and review its
                    terms.
                </p>
            </div>
            <div class="grid items-stretch gap-5 md:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="(offer, i) in product.offers"
                    :key="i"
                    :class="[
                        'rounded-xl border p-6',
                        offer.is_default ? 'border-primary bg-primary/5' : '',
                    ]"
                >
                    <p
                        v-if="offer.is_default"
                        class="mb-3 text-xs font-semibold uppercase"
                    >
                        Default package
                    </p>
                    <h3 class="text-xl font-semibold">{{ offer.name }}</h3>
                    <p class="mt-4 text-3xl font-semibold">
                        {{ formatPrice(offer.price, product.currency) }}
                    </p>
                    <p class="mt-3 text-sm capitalize">
                        {{ offer.license_type.replaceAll('_', ' ') }} license
                    </p>
                    <p
                        v-if="offer.description"
                        class="mt-4 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ offer.description }}
                    </p>
                    <dl class="mt-6 space-y-3 border-t pt-4 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt>Support</dt>
                            <dd>{{ days(offer.support_period_days) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt>Updates</dt>
                            <dd>{{ days(offer.updates_period_days) }}</dd>
                        </div>
                    </dl>
                    <Button
                        v-if="page.props.auth.user"
                        class="mt-5 w-full"
                        :disabled="cartForm.processing"
                        @click="addToCart(offer.offer_id)"
                        >Add to Cart</Button
                    >
                    <Link
                        v-else
                        :href="login()"
                        class="mt-5 block rounded-lg border px-4 py-3 text-center text-sm"
                        >Sign in to add to Cart</Link
                    >
                </article>
            </div>
        </section>
        <section v-if="product.demo_url" class="rounded-2xl border p-6 sm:p-8">
            <h2 class="text-2xl font-semibold">Explore the live demo</h2>
            <p class="mt-3 text-muted-foreground">
                See how the software fits your workflow. These credentials are
                for the public demonstration only.
            </p>
            <a
                :href="product.demo_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-5 inline-block rounded-lg bg-primary px-5 py-3 text-sm text-primary-foreground"
                >Open Live Demo ↗</a
            >
            <dl class="mt-5 flex flex-wrap gap-5">
                <div
                    v-for="[field, value] in [
                        ['Demo Username', product.demo_username],
                        ['Demo Password', product.demo_password],
                    ]"
                    :key="field ?? ''"
                >
                    <template v-if="value && field"
                        ><dt class="text-sm text-muted-foreground">
                            {{ field }}
                        </dt>
                        <dd class="mt-1 flex items-center gap-3">
                            <code class="rounded bg-muted p-2 select-all">{{
                                value
                            }}</code
                            ><Button
                                variant="outline"
                                size="sm"
                                :aria-label="`Copy ${field}`"
                                @click="copy(value, field)"
                                >Copy</Button
                            >
                        </dd></template
                    >
                </div>
            </dl>
            <p aria-live="polite" class="mt-3 text-sm">{{ copied }}</p>
            <a
                v-if="product.preview_video_url"
                :href="product.preview_video_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-4 inline-block text-sm underline"
                >Watch Preview ↗</a
            >
        </section>
        <section>
            <h2 class="text-2xl font-semibold">
                Designed for these industries
            </h2>
            <div class="mt-5 flex flex-wrap gap-3">
                <Link
                    v-for="industry in product.industries"
                    :key="industry.slug"
                    :href="industryShow(industry.slug)"
                    class="rounded-full border px-4 py-2 text-sm hover:bg-muted"
                    >{{ industry.name }}</Link
                >
            </div>
        </section>
        <section class="rounded-xl border p-6">
            <h2 class="text-2xl font-semibold">Support &amp; updates</h2>
            <p class="mt-3 text-muted-foreground">
                Support and update periods are listed separately for each
                package above. Compare those terms when choosing the right scope
                for your business.
            </p>
        </section>
        <section
            id="technical"
            class="detail-reading-section grid gap-8 border-t pt-8 lg:grid-cols-2"
        >
            <div>
                <h2 class="text-xl font-semibold">Technical information</h2>
                <p class="mt-4 text-sm text-muted-foreground">
                    Built with
                    {{
                        product.technologies
                            .map((technology) => technology.name)
                            .join(', ')
                    }}
                </p>
                <p class="mt-3 text-sm">
                    Version {{ product.release.version }}
                </p>
                <a
                    v-if="product.documentation_url"
                    :href="product.documentation_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-block text-sm underline"
                    >Public documentation ↗</a
                >
            </div>
            <div>
                <h2 class="text-xl font-semibold">Release information</h2>
                <p class="mt-3 text-sm text-muted-foreground">
                    Released
                    {{
                        new Date(
                            product.release.released_at,
                        ).toLocaleDateString()
                    }}
                </p>
                <p class="mt-4 text-sm whitespace-pre-line">
                    {{ product.release.changelog }}
                </p>
                <p
                    v-if="product.release.release_notes"
                    class="mt-3 text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ product.release.release_notes }}
                </p>
            </div>
        </section>
        <Link
            v-if="support_entry.url"
            :href="support_entry.url"
            class="inline-block rounded border p-3"
            >Get Support</Link
        >
        <section id="reviews" class="detail-reading-section">
            <ProductReviews :reviews="reviews" :slug="product.slug" />
        </section>
        <section v-if="related.length" class="space-y-6">
            <h2 class="text-2xl font-semibold">
                More software for your business
            </h2>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <ProductCard
                    v-for="item in related"
                    :key="item.slug"
                    :product="item"
                />
            </div>
        </section>
    </div>
</template>
