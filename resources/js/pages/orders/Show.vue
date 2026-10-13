<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatPrice } from '@/lib/marketplace';
import * as adminRoutes from '@/routes/admin/orders';
import * as customerRoutes from '@/routes/commerce/orders';
import { status as paymentStatus } from '@/routes/commerce/payments';
import { simulate } from '@/routes/commerce/payments';
import type { OrderDetail } from '@/types/commerce';
import * as refundRoutes from '@/routes/account/refunds';
import * as adminRefunds from '@/routes/admin/refunds';
import { useAuthorization } from '@/composables/useAuthorization';
const { can } = useAuthorization();
import { index as libraryIndex } from '@/routes/library';
const props = defineProps<{
    order: OrderDetail;
    admin: boolean;
    canCancel: boolean;
    canRetry: boolean;
}>();
const page = usePage();
const busy = ref(false);
const cancelling = ref(false);
const routes = props.admin ? adminRoutes : customerRoutes;
function post(url: string, data: Record<string, string> = {}) {
    router.post(url, data, {
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            cancelling.value = false;
        },
    });
}
</script>
<template>
    <Head :title="`Order ${order.order_number}`" />
    <div role="main" class="space-y-8 p-4 sm:p-6">
        <Link :href="routes.index()" class="text-sm underline">{{
            admin ? 'All Orders' : 'My Orders'
        }}</Link>
        <header class="space-y-3">
            <h1 class="text-2xl font-semibold">
                {{
                    order.status === 'completed'
                        ? 'Order confirmed'
                        : 'Order details'
                }}
            </h1>
            <p class="font-mono text-sm break-all">{{ order.order_number }}</p>
            <p class="capitalize">
                <StatusBadge :status="order.status" /> · Payment
                {{ order.payment_status }}
            </p>
            <p v-if="order.invoice_number" class="text-sm break-all">
                Settlement reference: {{ order.invoice_number }}
            </p>
        </header>
        <section
            v-if="order.documents.length"
            class="space-y-3 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">Invoices &amp; credit notes</h2>
            <div
                v-for="doc in order.documents"
                :key="doc.public_uuid"
                class="flex flex-wrap gap-4"
            >
                <Link :href="doc.url" class="underline"
                    >{{ doc.number }} ·
                    {{
                        doc.kind === 'credit_note' ? 'Credit note' : 'Invoice'
                    }}</Link
                >
                <a :href="doc.pdf_url" class="underline">Download PDF</a>
            </div>
        </section>
        <div
            v-if="Object.keys(page.props.errors).length"
            role="alert"
            class="rounded-lg border border-destructive p-4 text-destructive"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <section
            v-if="order.status === 'completed'"
            class="space-y-3 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">Software access</h2>
            <p>
                {{
                    order.software_access === 'active'
                        ? admin
                            ? 'Purchased software access is active.'
                            : 'Your software is available in My Library.'
                        : order.software_access === 'inactive'
                          ? 'Purchased software access is inactive.'
                          : 'Payment confirmed. Software access is being prepared.'
                }}
            </p>
            <Link v-if="!admin" :href="libraryIndex()" class="underline"
                >Open Library</Link
            >
        </section>
        <section class="space-y-5">
            <h2 class="text-xl font-semibold">
                {{
                    order.status === 'completed'
                        ? 'Purchased software'
                        : 'Selected software'
                }}
            </h2>
            <article
                v-for="(item, i) in order.items"
                :key="i"
                class="rounded-xl border p-5"
            >
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <Link
                            v-if="item.support_entry.url"
                            :href="item.support_entry.url"
                            class="inline-block rounded border p-3"
                            >Get Support</Link
                        >
                        <Link
                            v-if="!admin && item.review_entry.url"
                            :href="item.review_entry.url"
                            class="inline-block underline"
                            >{{ item.review_entry.label }}</Link
                        >
                        <h3 class="text-lg font-semibold">
                            {{ item.product_name }}
                        </h3>
                        <p class="mt-2">{{ item.offer_name }}</p>
                        <p class="mt-2 capitalize">
                            {{
                                item.refund_status === 'completed'
                                    ? 'Refunded'
                                    : item.refund_status === 'none'
                                      ? item.refund_explanation
                                      : 'Refund ' + item.refund_status
                            }}
                        </p>
                        <Link
                            v-if="
                                item.refund && (!admin || can('refunds.view'))
                            "
                            :href="
                                (admin ? adminRefunds : refundRoutes).show(
                                    item.refund.uuid,
                                )
                            "
                            class="underline"
                            >{{ item.refund.reference }}</Link
                        >
                        <p
                            v-if="item.offer_description"
                            class="mt-2 text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ item.offer_description }}
                        </p>
                        <p class="mt-2 text-sm capitalize">
                            {{ item.license_type.replaceAll('_', ' ') }} ·
                            Version
                            {{ item.version }}
                        </p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Support:
                            {{ item.support_period_days ?? 'Not specified'
                            }}{{
                                item.support_period_days === null ? '' : ' days'
                            }}
                            · Updates:
                            {{ item.updates_period_days ?? 'Not specified'
                            }}{{
                                item.updates_period_days === null ? '' : ' days'
                            }}
                        </p>
                    </div>
                    <p class="font-semibold">
                        {{ formatPrice(item.line_total, item.currency) }}
                    </p>
                </div>
            </article>
        </section>
        <section class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl border p-5">
                <h2 class="text-xl font-semibold">Billing information</h2>
                <p class="mt-4">{{ order.customer_name }}</p>
                <p v-if="order.company_name">{{ order.company_name }}</p>
                <p class="mt-2 break-words">{{ order.billing_email }}</p>
                <p v-if="order.phone">{{ order.phone }}</p>
                <p class="mt-3">
                    {{ order.address_line_1 }} {{ order.address_line_2 }}
                </p>
                <p>
                    {{ order.city }}, {{ order.state_region }}
                    {{ order.postal_code }}
                </p>
                <p>{{ order.country_code }}</p>
            </div>
            <div class="rounded-xl border p-5">
                <h2 class="text-xl font-semibold">Order total</h2>
                <dl class="mt-4 space-y-3">
                    <div class="flex justify-between">
                        <dt>Subtotal</dt>
                        <dd>
                            {{ formatPrice(order.subtotal, order.currency) }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between border-t pt-3 font-semibold"
                    >
                        <dt>Total</dt>
                        <dd>
                            {{ formatPrice(order.grand_total, order.currency) }}
                            {{ order.currency }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-muted-foreground">
                    Tax: {{ formatPrice(order.tax_total, order.currency) }}. No
                    discount applied. Software access is managed through your
                    purchase entitlement.
                </p>
                <p v-if="order.paid_at" class="mt-4 text-sm">
                    Paid {{ new Date(order.paid_at).toLocaleString() }}
                </p>
                <p v-if="order.cancelled_at" class="mt-4 text-sm">
                    Cancelled
                    {{ new Date(order.cancelled_at).toLocaleString() }}
                </p>
            </div>
        </section>
        <section
            v-if="order.status === 'completed'"
            class="space-y-3 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">
                Refunds: {{ order.refund_status }}
            </h2>
            <Link
                v-if="!admin && order.items.some((i) => i.refund_eligible)"
                :href="refundRoutes.create(order.order_number)"
                class="underline"
                >Request Refund</Link
            ><Link
                v-if="admin && can('refunds.manage') && can('refunds.view')"
                :href="adminRefunds.create(order.order_number)"
                class="underline"
                >Initiate Refund</Link
            >
        </section>
        <section class="space-y-5">
            <h2 class="text-xl font-semibold">Payment attempts</h2>
            <article
                v-for="(payment, i) in order.payments"
                :key="i"
                class="rounded-xl border p-5"
            >
                <p class="font-medium capitalize">
                    <StatusBadge :status="payment.status" /> —
                    {{ formatPrice(payment.amount, payment.currency) }}
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ new Date(payment.created_at).toLocaleString() }}
                </p>
                <p v-if="payment.failure_message" class="mt-3">
                    {{ payment.failure_message }}
                </p>
                <p v-if="admin" class="mt-3 text-sm break-all">
                    {{ payment.provider }} · {{ payment.provider_order_id }} ·
                    {{ payment.provider_payment_id }}
                </p>
                <p v-if="payment.provider_name" class="mt-2">
                    {{ payment.provider_name }}
                </p>
                <dl
                    v-if="admin && payment.external"
                    class="mt-3 space-y-2 text-sm break-all"
                >
                    <div v-for="(value, key) in payment.external" :key="key">
                        <dt>{{ key.replaceAll('_', ' ') }}</dt>
                        <dd>{{ value ?? 'Not recorded' }}</dd>
                    </div>
                </dl>
                <div
                    v-if="payment.simulation_key"
                    class="mt-5 rounded-lg border p-4"
                >
                    <p class="text-sm font-semibold">
                        Development payment simulator — no real charge
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <Button
                            :disabled="busy"
                            @click="
                                post(simulate.url(payment.simulation_key!), {
                                    outcome: 'paid',
                                })
                            "
                            >Simulate successful payment</Button
                        ><Button
                            variant="outline"
                            :disabled="busy"
                            @click="
                                post(simulate.url(payment.simulation_key!), {
                                    outcome: 'failed',
                                })
                            "
                            >Simulate failed payment</Button
                        >
                    </div>
                </div>
            </article>
        </section>
        <div class="flex flex-wrap gap-3">
            <Link
                v-if="
                    !admin &&
                    order.payment_status === 'pending' &&
                    order.payments.some((p) =>
                        ['Stripe', 'Razorpay'].includes(p.provider_name ?? ''),
                    )
                "
                :href="paymentStatus(order.order_number)"
                class="rounded border p-3"
                >Continue / check payment</Link
            >
            <Button
                v-if="canRetry && order.status === 'payment_failed'"
                :disabled="busy"
                @click="post(customerRoutes.retry.url(order.order_number))"
                >Retry payment</Button
            ><Button
                v-if="
                    canCancel &&
                    ['pending_payment', 'payment_failed'].includes(order.status)
                "
                variant="outline"
                :disabled="busy"
                @click="cancelling = true"
                >Cancel unpaid order</Button
            >
        </div>
        <Dialog v-model:open="cancelling"
            ><DialogContent
                ><DialogTitle>Cancel this unpaid order?</DialogTitle
                ><DialogDescription
                    >This closes the payment attempt. Your cart remains
                    available for a new checkout.</DialogDescription
                ><Button
                    variant="destructive"
                    :disabled="busy"
                    @click="post(routes.cancel.url(order.order_number))"
                    >Confirm cancellation</Button
                ></DialogContent
            ></Dialog
        >
    </div>
</template>
