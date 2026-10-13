<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatPrice } from '@/lib/marketplace';
import * as authorRoutes from '@/routes/author/earnings';
import * as adminRoutes from '@/routes/admin/earnings';
import type { Earning } from '@/types/earnings';
defineProps<{
    admin: boolean;
    earning: Earning;
    ledger: {
        type: string;
        amount: string;
        currency: string;
        occurred_at: string;
    }[];
}>();
const ledgerLabels: Record<string, string> = {
    earning_pending: 'Earning recorded as pending',
    earning_available: 'Earning released to available',
    refund_reversal: 'Refund adjustment (deducted)',
    payout_reserved: 'Reserved for payout',
    payout_paid: 'Payout paid',
    payout_released: 'Payout reservation released',
};
</script>
<template>
    <Head title="Earning Details" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <Link
            :href="admin ? adminRoutes.index() : authorRoutes.index()"
            class="underline"
            >Back to earnings</Link
        >
        <h1 class="text-3xl font-semibold">{{ earning.product }}</h1>
        <p>
            {{ earning.offer }} · Version {{ earning.version }} ·
            {{ earning.order_number }}
        </p>
        <dl class="grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
            <div v-if="admin">
                <dt>Author</dt>
                <dd>{{ earning.author }}</dd>
            </div>
            <div>
                <dt>Gross sale</dt>
                <dd>{{ formatPrice(earning.gross, earning.currency) }}</dd>
            </div>
            <div>
                <dt>Marketplace commission ({{ earning.rate }}%)</dt>
                <dd>{{ formatPrice(earning.commission, earning.currency) }}</dd>
            </div>
            <div>
                <dt>Original net earning</dt>
                <dd class="text-2xl font-semibold">
                    {{ formatPrice(earning.net, earning.currency) }}
                </dd>
            </div>
            <div>
                <dt>Refund adjustment</dt>
                <dd>
                    −{{
                        formatPrice(earning.refund_adjustment, earning.currency)
                    }}
                </dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd class="capitalize">
                    <StatusBadge :status="earning.status" />
                </dd>
            </div>
            <div>
                <dt>Hold period</dt>
                <dd>{{ earning.hold_days }} days</dd>
            </div>
            <div>
                <dt>Available from</dt>
                <dd>{{ new Date(earning.available_at).toLocaleString() }}</dd>
            </div>
        </dl>
        <section class="rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Ledger history</h2>
            <ol class="mt-4 space-y-3">
                <li v-for="(entry, i) in ledger" :key="i">
                    {{ ledgerLabels[entry.type] ?? entry.type }}
                    · {{ formatPrice(entry.amount, entry.currency) }} ·
                    {{ new Date(entry.occurred_at).toLocaleString() }}
                </li>
            </ol>
        </section>
        <p class="text-sm text-muted-foreground">
            Financial terms were recorded at checkout and remain unchanged by
            later price or commission changes. Payouts use a separate manual
            request and reservation workflow.
        </p>
    </div>
</template>
