<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/marketplace';
import { callback, start, status } from '@/routes/commerce/payments';
import { show as orderShow } from '@/routes/commerce/orders';
import { index as library } from '@/routes/library';
import type { OrderSummary } from '@/types/commerce';
const props = defineProps<{
    order: Pick<
        OrderSummary,
        | 'order_number'
        | 'status'
        | 'payment_status'
        | 'grand_total'
        | 'currency'
    >;
    handoff: {
        provider: 'stripe' | 'razorpay';
        url?: string;
        key?: string;
        order_id?: string;
        amount?: number;
        currency?: string;
        name?: string;
    } | null;
    canStart: boolean;
}>();
const page = usePage();
const busy = ref(false);
const message = ref('');
const timedOut = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let stopped = false;
let polls = 0;
type RazorpaySuccess = {
    razorpay_payment_id: string;
    razorpay_order_id: string;
    razorpay_signature: string;
};
type RazorpayOptions = {
    key: string;
    order_id: string;
    amount: number;
    currency: string;
    name: string;
    handler: (data: RazorpaySuccess) => void;
    modal: { ondismiss: () => void };
};
declare global {
    interface Window {
        Razorpay?: new (options: RazorpayOptions) => { open: () => void };
    }
}
function poll() {
    if (stopped || props.order.payment_status !== 'pending') return;
    if (++polls > 30) {
        timedOut.value = true;
        return;
    }
    timer = setTimeout(
        () =>
            router.reload({
                only: ['order', 'handoff', 'canStart'],
                onFinish: poll,
            }),
        2500,
    );
}
onMounted(poll);
onUnmounted(() => {
    stopped = true;
    clearTimeout(timer);
});
async function pay() {
    const handoff = props.handoff;
    if (!handoff || busy.value) return;
    if (handoff.provider === 'stripe' && handoff.url) {
        window.location.assign(handoff.url);
        return;
    }
    busy.value = true;
    try {
        if (!window.Razorpay)
            await new Promise<void>((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://checkout.razorpay.com/v1/checkout.js';
                script.onload = () => resolve();
                script.onerror = () =>
                    reject(new Error('Checkout unavailable'));
                document.head.appendChild(script);
            });
        if (
            !window.Razorpay ||
            !handoff.key ||
            !handoff.order_id ||
            handoff.amount === undefined ||
            !handoff.currency ||
            !handoff.name
        )
            throw new Error('Checkout unavailable');
        new window.Razorpay({
            key: handoff.key,
            order_id: handoff.order_id,
            amount: handoff.amount,
            currency: handoff.currency,
            name: handoff.name,
            handler: (data) =>
                router.post(callback.url(props.order.order_number), data, {
                    onFinish: () => {
                        busy.value = false;
                        router.reload({
                            only: ['order', 'handoff', 'canStart'],
                        });
                    },
                }),
            modal: {
                ondismiss: () => {
                    busy.value = false;
                    message.value =
                        'Checkout closed. Your payment remains unconfirmed until verified by the provider.';
                },
            },
        }).open();
    } catch {
        busy.value = false;
        message.value = 'Could not open checkout. Please try again shortly.';
    }
}
</script>
<template>
    <Head title="Payment status" />
    <main class="mx-auto max-w-3xl space-y-6 p-4 sm:p-8">
        <h1 class="text-2xl font-semibold">
            {{
                order.payment_status === 'paid'
                    ? 'Payment confirmed'
                    : order.payment_status === 'failed'
                      ? 'Payment failed'
                      : 'Payment awaiting confirmation'
            }}
        </h1>
        <p class="break-all">{{ order.order_number }}</p>
        <p>{{ formatPrice(order.grand_total, order.currency) }}</p>
        <p v-if="order.payment_status === 'pending'">
            Returning from checkout does not confirm payment. You can safely
            leave and check My Orders or Library later.
        </p>
        <p v-if="timedOut">
            Automatic checks have paused. Refresh or check your order later.
        </p>
        <div
            v-if="Object.keys(page.props.errors).length"
            role="alert"
            class="rounded border p-4"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <p v-if="message" role="status">{{ message }}</p>
        <Button
            v-if="handoff && order.payment_status === 'pending'"
            :disabled="busy"
            @click="pay"
            >Continue with
            {{ handoff.provider === 'stripe' ? 'Stripe' : 'Razorpay' }}</Button
        >
        <Button
            v-else-if="canStart"
            :disabled="busy"
            @click="
                router.post(
                    start.url(order.order_number),
                    {},
                    {
                        onStart: () => (busy = true),
                        onFinish: () => (busy = false),
                    },
                )
            "
            >Prepare payment checkout</Button
        >
        <nav class="flex flex-wrap gap-4">
            <Link :href="orderShow(order.order_number)" class="underline"
                >My Order</Link
            ><Link :href="library()" class="underline">Library</Link
            ><Link
                v-if="timedOut"
                :href="status(order.order_number)"
                class="underline"
                >Check again</Link
            >
        </nav>
    </main>
</template>
