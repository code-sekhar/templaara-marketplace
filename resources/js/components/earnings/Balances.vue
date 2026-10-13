<script setup lang="ts">
import { formatPrice } from '@/lib/marketplace';
import type { Balance } from '@/types/earnings';
defineProps<{ balances: Balance[] }>();
</script>
<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <article
            v-for="b in balances"
            :key="b.currency"
            class="panel space-y-4 p-5"
            :class="balances.length === 1 ? 'lg:col-span-2' : ''"
        >
            <h2 class="font-semibold">{{ b.currency }} earnings</h2>
            <dl
                class="balance-metrics grid grid-cols-2 gap-x-5 gap-y-4"
                :class="
                    balances.length === 1
                        ? 'sm:grid-cols-3 xl:grid-cols-4'
                        : 'sm:grid-cols-2'
                "
            >
                <div v-if="b.gross">
                    <dt>Gross author sales</dt>
                    <dd>{{ formatPrice(b.gross, b.currency) }}</dd>
                </div>
                <div v-if="b.commission">
                    <dt>Marketplace commission</dt>
                    <dd>{{ formatPrice(b.commission, b.currency) }}</dd>
                </div>
                <div>
                    <dt>Total earned</dt>
                    <dd>{{ formatPrice(b.earned, b.currency) }}</dd>
                </div>
                <div>
                    <dt>Pending</dt>
                    <dd>{{ formatPrice(b.pending, b.currency) }}</dd>
                </div>
                <div>
                    <dt class="text-success">Available</dt>
                    <dd class="text-xl font-semibold text-success">
                        {{ formatPrice(b.available, b.currency) }}
                    </dd>
                </div>
                <div v-if="b.reserved !== undefined">
                    <dt>Reserved for Payout</dt>
                    <dd>{{ formatPrice(b.reserved, b.currency) }}</dd>
                </div>
                <div v-if="b.paid !== undefined">
                    <dt>Paid Out</dt>
                    <dd>{{ formatPrice(b.paid, b.currency) }}</dd>
                </div>
                <div v-if="b.refund_adjustment !== undefined">
                    <dt>Refund adjustment</dt>
                    <dd>−{{ formatPrice(b.refund_adjustment, b.currency) }}</dd>
                </div>
                <div v-if="b.net_earned !== undefined">
                    <dt>Net earned after refunds</dt>
                    <dd>{{ formatPrice(b.net_earned, b.currency) }}</dd>
                </div>
                <div v-if="b.outstanding_adjustment !== undefined">
                    <dt>Outstanding adjustment</dt>
                    <dd>
                        {{ formatPrice(b.outstanding_adjustment, b.currency) }}
                    </dd>
                </div>
                <div v-if="b.gross_refunded !== undefined">
                    <dt>Gross refunded</dt>
                    <dd>{{ formatPrice(b.gross_refunded, b.currency) }}</dd>
                </div>
                <div v-if="b.commission_reversed !== undefined">
                    <dt>Commission reversed</dt>
                    <dd>
                        {{ formatPrice(b.commission_reversed, b.currency) }}
                    </dd>
                </div>
                <div v-if="b.net_commission !== undefined">
                    <dt>Net commission</dt>
                    <dd>{{ formatPrice(b.net_commission, b.currency) }}</dd>
                </div>
            </dl>
        </article>
    </div>
    <p v-if="!balances.length" class="rounded-lg border p-4">
        No earnings yet. Earnings appear after a verified paid sale.
    </p>
    <p class="text-sm text-muted-foreground">
        Currencies are tracked separately. Payouts are processed manually. Paid
        Out means a payout has been marked paid after manual transfer
        confirmation.
    </p>
</template>
