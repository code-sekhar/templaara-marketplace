<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useAuthorization } from '@/composables/useAuthorization';
import InputError from '@/components/InputError.vue';
import { formatPrice } from '@/lib/marketplace';
import * as customer from '@/routes/account/refunds';
import * as adminRoutes from '@/routes/admin/refunds';
import type { RefundData } from '@/types/refunds';
const props = defineProps<{ admin: boolean; refund: RefundData }>();
const { can } = useAuthorization();
const form = useForm({ external_reference: '', reason: '', admin_note: '' });
function action(a: 'approve' | 'process' | 'complete' | 'fail' | 'cancel') {
    if (
        !window.confirm(
            a === 'complete'
                ? 'Completing confirms that the external refund has been successfully issued. It reverses finances and revokes purchased access.'
                : 'Confirm this refund action?',
        )
    )
        return;
    form.post(
        props.admin
            ? adminRoutes[a].url(props.refund.uuid)
            : customer.cancel.url(props.refund.uuid),
        { onSuccess: () => form.reset() },
    );
}
</script>
<template>
    <Head :title="refund.reference" />
    <div role="main" class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <Link :href="(admin ? adminRoutes : customer).index()" class="underline"
            >Refund history</Link
        >
        <h1 class="text-2xl font-semibold break-all">{{ refund.reference }}</h1>
        <p class="text-xl capitalize">
            <StatusBadge :status="refund.status" /> ·
            {{ formatPrice(refund.amount, refund.currency) }}
        </p>
        <p class="break-all">Order {{ refund.order }}</p>
        <p v-if="admin">Customer: {{ refund.customer }}</p>
        <p>
            Purchase date:
            {{
                refund.purchase_date
                    ? new Date(refund.purchase_date).toLocaleDateString()
                    : ''
            }}
        </p>
        <p>Reason: {{ refund.reason_code.replaceAll('_', ' ') }}</p>
        <p class="whitespace-pre-line">{{ refund.customer_reason }}</p>
        <article
            v-for="(i, n) in refund.items"
            :key="n"
            class="space-y-3 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">
                {{ i.product }} · v{{ i.version }}
            </h2>
            <p>{{ formatPrice(i.amount, refund.currency) }}</p>
            <template v-if="admin"
                ><p>
                    License: {{ i.license_status }} · Download access issued:
                    {{ i.download_count }} · Activations:
                    {{ i.activation_count }}
                </p>
                <p>
                    Earning: {{ i.earning_status ?? 'Official product' }} ·
                    Author adjustment:
                    {{
                        formatPrice(i.author_amount ?? '0.00', refund.currency)
                    }}
                    · Commission reversal:
                    {{
                        formatPrice(
                            i.commission_amount ?? '0.00',
                            refund.currency,
                        )
                    }}
                </p>
                <p>
                    Unresolved payouts:
                    {{ i.payout_exposure?.join(', ') || 'None' }} · Already paid
                    exposure:
                    {{
                        formatPrice(i.paid_exposure ?? '0.00', refund.currency)
                    }}
                </p></template
            >
        </article>
        <p v-if="refund.completed_at" class="rounded border p-4">
            Refund completed on
            {{ new Date(refund.completed_at).toLocaleString() }}. Purchased
            access is revoked; your purchase remains in history.
        </p>
        <p v-if="refund.failure_reason" role="alert">
            {{ refund.failure_reason }}
        </p>
        <p v-if="refund.cancellation_reason">
            {{ refund.cancellation_reason }}
        </p>
        <p v-if="admin && refund.external_reference">
            External reference: {{ refund.external_reference }}
        </p>
        <p v-if="admin && refund.admin_note">
            Internal note: {{ refund.admin_note }}
        </p>
        <section
            v-if="
                (admin &&
                    can('refunds.manage') &&
                    ['requested', 'approved', 'processing'].includes(
                        refund.status,
                    )) ||
                (!admin && refund.status === 'requested')
            "
            class="space-y-4 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">
                {{ admin ? 'Manual refund actions' : 'Cancel request' }}
            </h2>
            <p v-if="admin">
                Completing a refund confirms that the external refund has been
                successfully issued. Resolve unpaid author payouts before
                sending the external refund. If money was already returned,
                resolve the conflict and retry completion; do not mark it
                failed.
            </p>
            <label v-if="admin" class="block"
                >Internal note<textarea
                    v-model="form.admin_note"
                    class="block w-full rounded border p-2"
                /></label
            ><label v-if="admin" class="block"
                >Failure / cancellation reason<textarea
                    v-model="form.reason"
                    class="block w-full rounded border p-2" /><InputError
                    :message="form.errors.reason" /></label
            ><label v-if="admin && refund.status === 'processing'" class="block"
                >External refund reference<input
                    id="external_reference"
                    v-model="form.external_reference"
                    class="block w-full rounded border p-2" /><InputError
                    :message="form.errors.external_reference" /></label
            ><InputError
                :message="(form.errors as Record<string, string>).refund"
            />
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="admin && refund.status === 'requested'"
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="action('approve')"
                >
                    Approve</button
                ><button
                    v-if="admin && refund.status === 'approved'"
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="action('process')"
                >
                    Start Processing</button
                ><template v-if="admin && refund.status === 'processing'"
                    ><button
                        :disabled="form.processing"
                        class="rounded border p-3"
                        @click="action('complete')"
                    >
                        Complete Refund</button
                    ><button
                        :disabled="form.processing"
                        class="rounded border p-3"
                        @click="action('fail')"
                    >
                        Mark Failed
                    </button></template
                ><button
                    v-if="
                        refund.status === 'requested' ||
                        (admin && refund.status === 'approved')
                    "
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="action('cancel')"
                >
                    Cancel Refund
                </button>
            </div>
        </section>
        <section class="rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Refund timeline</h2>
            <ol class="mt-4 space-y-3">
                <li v-for="(e, i) in refund.events" :key="i">
                    {{ e.previous_status ?? 'New' }} →
                    <StatusBadge :status="e.status" /> ·
                    {{ new Date(e.occurred_at).toLocaleString() }}
                    <p>{{ e.reason }}</p>
                </li>
            </ol>
        </section>
    </div>
</template>
