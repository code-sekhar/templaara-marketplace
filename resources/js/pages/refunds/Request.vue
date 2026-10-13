<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { formatPrice } from '@/lib/marketplace';
import * as customer from '@/routes/account/refunds';
import * as adminRoutes from '@/routes/admin/refunds';
const props = defineProps<{
    admin: boolean;
    order: {
        reference: string;
        currency: string;
        items: {
            item_id: number;
            product_name: string;
            version: string;
            line_total: string;
            refund_eligible: boolean;
            refund_status: string;
            refund_explanation: string;
        }[];
    };
}>();
const form = useForm({
    items: [] as number[],
    reason_code: 'customer_request',
    customer_reason: '',
    request_key: crypto.randomUUID(),
});
function submit() {
    form.post(
        (props.admin ? adminRoutes.store : customer.store).url(
            props.order.reference,
        ),
    );
}
</script>
<template>
    <Head title="Request Refund" />
    <div role="main" class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
        <h1 class="text-3xl font-semibold">Request Refund</h1>
        <p class="break-all">{{ order.reference }}</p>
        <p>
            Select whole purchased items. Templaara reviews eligibility;
            submitting does not authorize or issue a refund.
        </p>
        <form class="space-y-5" @submit.prevent="submit">
            <label
                v-for="i in order.items"
                :key="i.item_id"
                class="flex gap-3 rounded-xl border p-4"
                ><input
                    v-model="form.items"
                    type="checkbox"
                    :value="i.item_id"
                    :disabled="!i.refund_eligible"
                /><span
                    ><strong>{{ i.product_name }}</strong> · v{{ i.version }} ·
                    {{ formatPrice(i.line_total, order.currency)
                    }}<span class="block">{{
                        i.refund_status === 'none'
                            ? i.refund_explanation
                            : i.refund_status.replaceAll('_', ' ')
                    }}</span></span
                ></label
            ><InputError :message="form.errors.items" /><label class="block"
                >Reason<select
                    v-model="form.reason_code"
                    class="mt-1 block w-full rounded border p-3"
                >
                    <option
                        v-for="r in [
                            'duplicate_purchase',
                            'product_not_as_described',
                            'technical_issue',
                            'customer_request',
                            'fraud_or_risk',
                            'other',
                        ]"
                        :key="r"
                        :value="r"
                    >
                        {{ r.replaceAll('_', ' ') }}
                    </option></select
                ><InputError :message="form.errors.reason_code" /></label
            ><label class="block"
                >Explanation (optional)<textarea
                    v-model="form.customer_reason"
                    class="mt-1 block w-full rounded border p-3" /><InputError
                    :message="form.errors.customer_reason" /></label
            ><InputError
                :message="(form.errors as Record<string, string>).refund"
            /><button
                :disabled="form.processing || !form.items.length"
                class="rounded border p-3 disabled:opacity-50"
            >
                {{ form.processing ? 'Submitting…' : 'Submit Refund Request' }}
            </button>
            <Link
                :href="(admin ? adminRoutes : customer).index()"
                class="underline"
                >Refund History</Link
            >
        </form>
    </div>
</template>
