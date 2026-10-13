<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { formatPrice } from '@/lib/marketplace';
import { useAuthorization } from '@/composables/useAuthorization';
import * as authorRoutes from '@/routes/author/payouts';
import * as adminRoutes from '@/routes/admin/payouts';
import type { Payout } from '@/types/payouts';
const props = defineProps<{ admin: boolean; payout: Payout }>();
const { can } = useAuthorization();
const form = useForm({ external_reference: '', reason: '', admin_note: '' });
const instructions = ref<Record<string, string> | null>(null);
const revealError = ref('');
const revealing = ref(false);
function transition(action: 'approve' | 'process' | 'pay' | 'fail' | 'cancel') {
    if (
        !window.confirm(
            action === 'pay'
                ? 'Confirm that the manual transfer was completed to this snapshotted destination. This records a terminal paid payout.'
                : 'Confirm this payout action?',
        )
    )
        return;
    const url = props.admin
        ? adminRoutes[action].url(props.payout.uuid)
        : authorRoutes.cancel.url(props.payout.uuid);
    form.post(url, {
        onSuccess: () => {
            instructions.value = null;
            form.reset();
        },
    });
}
async function reveal() {
    revealing.value = true;
    revealError.value = '';
    try {
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.slice(11) ?? '',
        );
        const res = await fetch(
            adminRoutes.instructions.url(props.payout.uuid),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
            },
        );
        if (!res.ok)
            throw new Error(
                'Unable to reveal instructions. Refresh and check permissions.',
            );
        instructions.value = (await res.json()).instructions;
    } catch (e) {
        revealError.value =
            e instanceof Error ? e.message : 'Unable to reveal instructions.';
    } finally {
        revealing.value = false;
    }
}
</script>
<template>
    <Head :title="payout.reference" />
    <div role="main" class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <Link
            :href="admin ? adminRoutes.index() : authorRoutes.index()"
            class="underline"
            >Back to payouts</Link
        >
        <h1 class="text-2xl font-semibold break-all">{{ payout.reference }}</h1>
        <p class="text-xl">
            {{ formatPrice(payout.amount, payout.currency) }} ·
            <span class="capitalize"
                ><StatusBadge :status="payout.status"
            /></span>
        </p>
        <p v-if="admin">Author: {{ payout.author }}</p>
        <section class="space-y-3 rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Snapshotted destination</h2>
            <p>
                {{ payout.method.label }} · Bank Transfer ·
                {{ payout.method.bank_name }} ·
                {{ payout.method.masked_account }}
            </p>
            <p>
                {{ payout.method.currency }} · {{ payout.method.country_code }}
            </p>
            <p class="text-sm text-muted-foreground">
                Manual processing by Templaara. This request retains its
                original destination when saved methods change.
            </p>
            <button
                v-if="
                    admin &&
                    can('payouts.manage') &&
                    ['approved', 'processing'].includes(payout.status) &&
                    !instructions
                "
                :disabled="revealing"
                class="rounded border p-3"
                @click="reveal"
            >
                {{ revealing ? 'Revealing…' : 'Reveal transfer instructions' }}
            </button>
            <p v-if="revealError" role="alert">{{ revealError }}</p>
            <dl v-if="instructions" class="space-y-2 break-all">
                <div v-for="(value, key) in instructions" :key="key">
                    <dt class="font-medium">{{ key.replaceAll('_', ' ') }}</dt>
                    <dd>{{ value }}</dd>
                </div>
                <button class="underline" @click="instructions = null">
                    Hide instructions
                </button>
            </dl>
        </section>
        <section class="rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Allocation breakdown</h2>
            <ul class="mt-4 space-y-2">
                <li v-for="(a, i) in payout.allocations" :key="i">
                    {{ a.product }} · v{{ a.version }} ·
                    {{ formatPrice(a.amount, payout.currency) }}
                </li>
            </ul>
        </section>
        <p v-if="payout.failure_reason" role="alert" class="rounded border p-4">
            Failure: {{ payout.failure_reason }}
        </p>
        <p v-if="payout.cancellation_reason" class="rounded border p-4">
            Cancellation: {{ payout.cancellation_reason }}
        </p>
        <p v-if="admin && payout.admin_note">
            Internal note: {{ payout.admin_note }}
        </p>
        <p v-if="admin && payout.external_reference">
            Transfer reference: {{ payout.external_reference }}
        </p>
        <section
            v-if="
                (admin &&
                    can('payouts.manage') &&
                    ['requested', 'approved', 'processing'].includes(
                        payout.status,
                    )) ||
                (!admin && payout.status === 'requested')
            "
            class="space-y-4 rounded-xl border p-5"
        >
            <h2 class="text-xl font-semibold">
                {{ admin ? 'Manual payout actions' : 'Cancel request' }}
            </h2>
            <label v-if="admin" class="block"
                >Internal admin note<textarea
                    v-model="form.admin_note"
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.admin_note" /></label
            ><label v-if="admin" class="block"
                >Failure / cancellation reason<textarea
                    v-model="form.reason"
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.reason" /></label
            ><label v-if="admin && payout.status === 'processing'" class="block"
                >External transfer reference<input
                    id="external_reference"
                    v-model="form.external_reference"
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.external_reference" /></label
            ><InputError
                :message="(form.errors as Record<string, string>).payout"
            />
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="admin && payout.status === 'requested'"
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="transition('approve')"
                >
                    Approve</button
                ><button
                    v-if="admin && payout.status === 'approved'"
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="transition('process')"
                >
                    Start Processing</button
                ><template v-if="admin && payout.status === 'processing'"
                    ><button
                        :disabled="form.processing"
                        class="rounded border p-3"
                        @click="transition('pay')"
                    >
                        Mark Paid</button
                    ><button
                        :disabled="form.processing"
                        class="rounded border p-3"
                        @click="transition('fail')"
                    >
                        Mark Failed
                    </button></template
                ><button
                    v-if="['requested', 'approved'].includes(payout.status)"
                    :disabled="form.processing"
                    class="rounded border p-3"
                    @click="transition('cancel')"
                >
                    Cancel Payout
                </button>
            </div>
        </section>
        <section class="rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Review history</h2>
            <ol class="mt-4 space-y-3">
                <li v-for="(e, i) in payout.events" :key="i">
                    {{ e.previous_status ?? 'New request' }} →
                    <StatusBadge :status="e.status" /> ·
                    {{ new Date(e.occurred_at).toLocaleString() }}
                    <p v-if="admin && e.actor">By {{ e.actor }}</p>
                    <p v-if="e.reason">{{ e.reason }}</p>
                </li>
            </ol>
        </section>
    </div>
</template>
