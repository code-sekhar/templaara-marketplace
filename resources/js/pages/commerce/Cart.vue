<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import EmptyState from '@/components/design/EmptyState.vue';
import { Trash2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/marketplace';
import { destroy, store } from '@/routes/commerce/cart';
import { index as checkout } from '@/routes/commerce/checkout';
import { index as browse } from '@/routes/marketplace/products';
import type { CartData } from '@/types/commerce';
defineProps<{ cart: CartData }>();
const page = usePage();
const selected = reactive<Record<number, number>>({});
const busy = ref(false);
function change(slug: string | null, offer: number | null | undefined) {
    if (!slug || !offer) return;
    router.post(
        store.url(),
        { product_slug: slug, offer_id: offer },
        {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}
function days(value: number | null) {
    return value === null ? 'Not specified' : `${value} days`;
}
function remove(id: number) {
    router.delete(destroy.url(id), {
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}
</script>
<template>
    <Head title="Your cart" />
    <div class="premium-cart space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs font-semibold tracking-wider text-primary uppercase"
                >
                    Your software selection
                </p>
                <h1 class="mt-2 text-3xl font-semibold">Shopping cart</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Review the packages that fit your business.
                </p>
            </div>
            <Link
                :href="browse()"
                class="rounded-lg border bg-white px-4 py-2.5 text-sm font-medium hover:bg-brand-blue-soft"
                >Continue browsing →</Link
            >
        </header>
        <div
            v-if="Object.keys(page.props.errors).length"
            role="alert"
            class="rounded-xl border border-destructive p-4 text-sm text-destructive"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <EmptyState
            v-if="!cart.items.length"
            illustration="/illustrations/catalog-discovery.svg"
            title="Your next business tool starts here"
            description="Explore software and choose a package to add it to your cart."
            ><Link
                :href="browse()"
                class="mt-5 inline-block rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-primary-foreground"
                >Browse Software</Link
            ></EmptyState
        >
        <div
            v-else
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]"
        >
            <section
                class="cart-selection-panel min-w-0"
                aria-label="Cart selections"
            >
                <p class="pb-4 text-xs font-medium text-muted-foreground">
                    {{ cart.items.length }}
                    {{
                        cart.items.length === 1
                            ? 'software selection'
                            : 'software selections'
                    }}
                    · {{ cart.currency }}
                </p>
                <article
                    v-for="item in cart.items"
                    :key="item.id"
                    class="cart-item border-t bg-white py-5"
                >
                    <div
                        class="grid items-start gap-4 sm:grid-cols-[168px_minmax(0,1fr)]"
                    >
                        <div
                            class="aspect-video overflow-hidden rounded-xl border bg-brand-blue-soft"
                        >
                            <img
                                v-if="item.cover"
                                :src="item.cover.url"
                                :alt="item.cover.alt"
                                class="h-full w-full object-contain"
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center text-xs text-muted-foreground"
                            >
                                Preview unavailable
                            </div>
                        </div>
                        <div class="min-w-0 space-y-3">
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <p
                                        v-if="item.category"
                                        class="mb-1 text-xs text-muted-foreground"
                                    >
                                        {{ item.category.name }}
                                    </p>
                                    <h2 class="text-base font-semibold">
                                        <Link
                                            v-if="item.slug"
                                            :href="`/products/${item.slug}`"
                                            class="hover:text-primary"
                                            >{{ item.name }}</Link
                                        ><span v-else>{{ item.name }}</span>
                                    </h2>
                                </div>
                                <strong
                                    class="shrink-0 text-base tabular-nums"
                                    >{{
                                        formatPrice(
                                            item.price,
                                            cart.currency ?? 'INR',
                                        )
                                    }}</strong
                                >
                            </div>
                            <div
                                class="cart-package-controls grid items-end gap-2"
                            >
                                <div
                                    v-if="item.offers.length"
                                    class="min-w-0 grow"
                                >
                                    <label
                                        :for="`package-${item.id}`"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Package selection</label
                                    ><select
                                        :id="`package-${item.id}`"
                                        :value="
                                            selected[item.id] ?? item.offer_id
                                        "
                                        class="h-10 w-full rounded-lg border bg-background px-3 text-sm"
                                        @change="
                                            selected[item.id] = Number(
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            )
                                        "
                                    >
                                        <option
                                            v-for="offer in item.offers"
                                            :key="offer.offer_id"
                                            :value="offer.offer_id"
                                        >
                                            {{ offer.name }} —
                                            {{
                                                formatPrice(
                                                    offer.price,
                                                    cart.currency ?? 'INR',
                                                )
                                            }}
                                        </option>
                                    </select>
                                </div>
                                <Button
                                    v-if="item.offers.length"
                                    variant="outline"
                                    size="sm"
                                    class="h-10"
                                    :disabled="
                                        busy ||
                                        (!item.issue &&
                                            (!selected[item.id] ||
                                                selected[item.id] ===
                                                    item.offer_id))
                                    "
                                    @click="
                                        change(
                                            item.slug,
                                            selected[item.id] ?? item.offer_id,
                                        )
                                    "
                                    >{{
                                        item.issue
                                            ? 'Confirm updated package'
                                            : 'Update package'
                                    }}</Button
                                >
                                <button
                                    type="button"
                                    class="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg border px-3 text-xs font-medium text-muted-foreground hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                    :disabled="busy"
                                    @click="remove(item.id)"
                                >
                                    <Trash2
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />Remove
                                </button>
                            </div>
                            <template
                                v-for="offer in item.offers.filter(
                                    (o) => o.offer_id === item.offer_id,
                                )"
                                :key="offer.offer_id"
                                ><p
                                    class="text-xs text-muted-foreground capitalize"
                                >
                                    {{
                                        offer.license_type.replaceAll('_', ' ')
                                    }}
                                    license · {{ offer.activation_limit }}
                                    {{
                                        offer.activation_limit === 1
                                            ? 'activation'
                                            : 'activations'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Support:
                                    {{ days(offer.support_period_days) }} ·
                                    Updates:
                                    {{ days(offer.updates_period_days) }}
                                </p></template
                            >
                            <p
                                v-if="
                                    selected[item.id] &&
                                    selected[item.id] !== item.offer_id
                                "
                                class="rounded-lg border border-primary/20 bg-brand-blue-soft px-3 py-2 text-xs font-medium text-primary"
                                aria-live="polite"
                            >
                                Package change not saved. Update to confirm.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="item.issue"
                        role="alert"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        <p>{{ item.issue }}</p>
                        <p
                            v-if="item.price !== item.previous_price"
                            class="mt-2"
                        >
                            Previous cart price:
                            {{
                                formatPrice(
                                    item.previous_price,
                                    cart.currency ?? 'INR',
                                )
                            }}
                        </p>
                    </div>
                </article>
            </section>
            <aside class="panel purchase-panel cart-order-summary p-6">
                <h2 class="text-xl font-semibold">Order summary</h2>
                <dl class="mt-6 space-y-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt>Subtotal</dt>
                        <dd class="font-medium">
                            {{
                                formatPrice(
                                    cart.subtotal,
                                    cart.currency ?? 'INR',
                                )
                            }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t pt-4 text-base font-semibold"
                    >
                        <dt>Cart total</dt>
                        <dd>
                            {{
                                formatPrice(cart.total, cart.currency ?? 'INR')
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs leading-5 text-muted-foreground">
                    Your final tax and payable total are calculated at checkout
                    using your billing information and the current tax policy.
                </p>
                <Link
                    v-if="cart.valid && !busy"
                    :href="checkout()"
                    aria-label="Proceed to Checkout"
                    class="mt-6 block rounded-xl bg-primary px-4 py-3.5 text-center text-sm font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring"
                    >Proceed to Checkout →</Link
                >
                <p v-else class="mt-6 rounded-lg bg-muted p-3 text-sm">
                    {{
                        busy
                            ? 'Updating your selection…'
                            : 'Resolve the selection issues before checkout.'
                    }}
                </p>
            </aside>
        </div>
    </div>
</template>
