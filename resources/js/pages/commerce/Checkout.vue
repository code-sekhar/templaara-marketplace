<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/marketplace';
import { index as cartIndex } from '@/routes/commerce/cart';
import { store } from '@/routes/commerce/checkout';
import type { CartData } from '@/types/commerce';
const props = defineProps<{
    cart: CartData;
    defaults: { customer_name: string; billing_email: string };
    available: boolean;
    invoiceEnabled: boolean;
}>();
const form = useForm({
    idempotency_key: props.cart.checkout_key,
    customer_name: props.defaults.customer_name,
    company_name: '',
    billing_email: props.defaults.billing_email,
    phone: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    state_region: '',
    postal_code: '',
    country_code: '',
    gst_state_code: '',
    customer_gstin: '',
    customer_tax_id: '',
    tax_quote_hash: '',
});
watch(
    () => props.cart.checkout_key,
    (key) => (form.idempotency_key = key),
);
const fields = [
    ['customer_name', 'Customer name', true],
    ['company_name', 'Company name', false],
    ['billing_email', 'Billing email', true],
    ['phone', 'Phone', false],
    ['address_line_1', 'Address line 1', true],
    ['address_line_2', 'Address line 2', false],
    ['city', 'City', true],
    ['state_region', 'State / Region', true],
    ['postal_code', 'Postal code', true],
    ['country_code', 'Country code (e.g. IN, US, GB)', true],
    ['gst_state_code', 'Indian place-of-supply state code (01–38)', false],
    ['customer_gstin', 'Business GSTIN', false],
    ['customer_tax_id', 'Business tax / VAT ID', false],
] as const;
type TaxQuote = {
    subtotal: string;
    tax_total: string;
    taxable_subtotal: string;
    grand_total: string;
    inclusive: boolean;
    hash: string;
    lines: { components: { type: string; rate: string; amount: string }[] }[];
};
const taxQuote = ref<TaxQuote | null>(null);
const taxError = ref('');
const taxLoading = ref(false);
let quoteSequence = 0;
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
onUnmounted(() => {
    clearTimeout(quoteTimer);
    ++quoteSequence;
});
watch(
    () => [
        form.country_code,
        form.gst_state_code,
        form.customer_gstin,
        props.cart.checkout_key,
    ],
    async () => {
        if (!props.invoiceEnabled) return;
        const sequence = ++quoteSequence;
        clearTimeout(quoteTimer);
        taxLoading.value = false;
        taxQuote.value = null;
        form.tax_quote_hash = '';
        taxError.value = '';
        if (form.country_code.length !== 2) return;
        taxLoading.value = true;
        quoteTimer = setTimeout(async () => {
            try {
                const cookie = document.cookie
                    .split('; ')
                    .find((value) => value.startsWith('XSRF-TOKEN='));
                const response = await fetch('/checkout/quote', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            cookie?.slice(11) ?? '',
                        ),
                    },
                    body: JSON.stringify({
                        country_code: form.country_code,
                        gst_state_code: form.gst_state_code || null,
                        customer_gstin: form.customer_gstin || null,
                    }),
                });
                const data = await response.json();
                if (sequence !== quoteSequence) return;
                if (!response.ok) {
                    taxError.value = data.message ?? 'Tax quote unavailable.';
                    return;
                }
                taxQuote.value = data;
                form.tax_quote_hash = data.hash;
            } catch {
                if (sequence === quoteSequence)
                    taxError.value = 'Unable to confirm tax. Please try again.';
            } finally {
                if (sequence === quoteSequence) taxLoading.value = false;
            }
        }, 300);
    },
);
</script>
<template>
    <Head title="Checkout" />
    <div class="space-y-8">
        <h1 class="text-3xl font-semibold">Checkout</h1>
        <p v-if="!available" role="status" class="rounded-lg border p-4">
            Checkout is temporarily unavailable. Your cart will remain saved.
        </p>
        <p v-if="!cart.valid" role="alert" class="rounded-lg border p-4">
            Your selection needs attention.
            <Link :href="cartIndex()" class="underline">Review your cart</Link>
            before placing an order.
        </p>
        <form
            class="grid items-start gap-8 lg:grid-cols-[1fr_350px]"
            @submit.prevent="form.post(store.url())"
        >
            <section class="space-y-6">
                <h2 class="text-xl font-semibold">Billing information</h2>
                <p class="text-sm text-muted-foreground">
                    Billing email may differ from your account email. Your
                    account details will stay unchanged.
                </p>
                <InputError
                    v-for="(error, key) in form.errors"
                    :key="key"
                    :message="error"
                />
                <div class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-for="[field, label, required] in fields"
                        :key="field"
                    >
                        <label :for="field" class="mb-2 block text-sm"
                            >{{ label }}
                            {{ required ? '*' : '(optional)' }}</label
                        ><Input
                            :id="field"
                            :aria-invalid="!!form.errors[field]"
                            :aria-describedby="
                                form.errors[field]
                                    ? `${field}-error`
                                    : undefined
                            "
                            v-model="form[field]"
                            :type="
                                field === 'billing_email'
                                    ? 'email'
                                    : field === 'phone'
                                      ? 'tel'
                                      : 'text'
                            "
                            :required="required"
                            :maxlength="
                                field === 'country_code'
                                    ? 2
                                    : field === 'postal_code'
                                      ? 30
                                      : field === 'phone'
                                        ? 40
                                        : field === 'city' ||
                                            field === 'state_region'
                                          ? 100
                                          : 255
                            "
                            @blur="
                                field === 'country_code' &&
                                (form.country_code =
                                    form.country_code.toUpperCase())
                            "
                        />
                    </div>
                </div>
            </section>
            <aside class="space-y-5 rounded-xl border p-6">
                <h2 class="text-xl font-semibold">Your order</h2>
                <div
                    v-for="item in cart.items"
                    :key="item.id"
                    class="border-b pb-4"
                >
                    <h3 class="font-medium">{{ item.name }}</h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            item.offers.find(
                                (offer) => offer.offer_id === item.offer_id,
                            )?.name
                        }}
                    </p>
                    <p class="mt-2">
                        {{ formatPrice(item.price, cart.currency ?? 'INR') }}
                    </p>
                    <p v-if="item.issue" class="mt-2 text-sm text-destructive">
                        {{ item.issue }}
                    </p>
                </div>
                <div class="flex justify-between gap-3 text-lg font-semibold">
                    <span>Total</span
                    ><span>{{
                        formatPrice(
                            taxQuote?.grand_total ?? cart.total,
                            cart.currency ?? 'INR',
                        )
                    }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        props.invoiceEnabled
                            ? 'Enter billing jurisdiction to confirm configured tax. No discount is applied.'
                            : 'No tax or discount is applied.'
                    }}
                </p>
                <p v-if="taxLoading" role="status">Confirming tax…</p>
                <p v-if="taxError" role="alert" class="text-destructive">
                    {{ taxError }}
                </p>
                <div v-if="taxQuote" class="space-y-2">
                    <p>
                        Taxable amount
                        {{
                            formatPrice(
                                taxQuote.taxable_subtotal,
                                cart.currency ?? 'INR',
                            )
                        }}
                    </p>
                    <p>
                        Tax
                        {{
                            formatPrice(
                                taxQuote.tax_total,
                                cart.currency ?? 'INR',
                            )
                        }}
                        {{ taxQuote.inclusive ? '(included)' : '(added)' }}
                    </p>
                    <div v-for="(line, index) in taxQuote.lines" :key="index">
                        <p
                            v-for="component in line.components"
                            :key="component.type"
                        >
                            {{ component.type }} {{ component.rate }}% ·
                            {{
                                formatPrice(
                                    component.amount,
                                    cart.currency ?? 'INR',
                                )
                            }}
                        </p>
                    </div>
                </div>
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="
                        form.processing ||
                        !cart.valid ||
                        !available ||
                        taxLoading ||
                        (invoiceEnabled && !taxQuote)
                    "
                    >{{
                        form.processing ? 'Placing order…' : 'Place Order'
                    }}</Button
                ><Link
                    :href="cartIndex()"
                    class="block text-center text-sm underline"
                    >Back to Cart</Link
                >
            </aside>
        </form>
    </div>
</template>
