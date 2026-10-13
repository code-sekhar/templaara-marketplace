<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { useAuthorization } from '@/composables/useAuthorization';
import * as routes from '@/routes/admin/commissions';
import type { Page } from '@/types/earnings';
interface Policy {
    id: number;
    name: string;
    percentage: string;
    hold_days: number;
    is_active: boolean;
    is_default: boolean;
    effective_from: string;
    effective_until: string | null;
}
defineProps<{ policies: Page<Policy> }>();
const { can } = useAuthorization();
const form = useForm({
    name: '',
    percentage: '20.00',
    hold_days: 7,
    effective_from: '',
});
function submit() {
    form.transform((d) => ({
        ...d,
        effective_from: d.effective_from
            ? new Date(d.effective_from).toISOString()
            : '',
    })).post(routes.store.url(), { onSuccess: () => form.reset() });
}
function deactivate(id: number) {
    if (
        window.confirm(
            'Deactivate this policy? Author checkout will pause if no effective policy remains.',
        )
    )
        router.post(routes.deactivate.url(id));
}
</script>
<template>
    <Head title="Commission Policies" />
    <div role="main" class="space-y-6 p-4 sm:p-6">
        <h1 class="text-3xl font-semibold">Commission Policies</h1>
        <p>
            New policies apply to future checkouts. Purchased terms remain
            immutable.
        </p>
        <form
            v-if="can('commissions.manage')"
            class="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"
            @submit.prevent="submit"
        >
            <h2 class="text-xl font-semibold sm:col-span-2">
                Schedule a new default policy
            </h2>
            <label
                >Name<input
                    v-model="form.name"
                    required
                    maxlength="255"
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.name" /></label
            ><label
                >Commission percentage<input
                    v-model="form.percentage"
                    type="text"
                    inputmode="decimal"
                    required
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.percentage" /></label
            ><label
                >Hold days<input
                    v-model="form.hold_days"
                    type="number"
                    min="0"
                    max="3650"
                    required
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.hold_days" /></label
            ><label
                >Effective from (your local time)<input
                    v-model="form.effective_from"
                    type="datetime-local"
                    required
                    class="mt-1 block w-full rounded border p-2" /><InputError
                    :message="form.errors.effective_from" /></label
            ><button
                :disabled="form.processing"
                class="rounded-lg border px-4 py-3 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Schedule policy' }}
            </button>
        </form>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th class="p-3">Policy</th>
                        <th class="p-3">Rate / Hold</th>
                        <th class="p-3">Effective period</th>
                        <th class="p-3">State</th>
                        <th class="p-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in policies.data" :key="p.id" class="border-t">
                        <td class="p-3">{{ p.name }}</td>
                        <td class="p-3">
                            {{ p.percentage }}% / {{ p.hold_days }} days
                        </td>
                        <td class="p-3">
                            {{ new Date(p.effective_from).toLocaleString() }} →
                            {{
                                p.effective_until
                                    ? new Date(
                                          p.effective_until,
                                      ).toLocaleString()
                                    : 'Open ended'
                            }}
                        </td>
                        <td class="p-3">
                            {{ p.is_active ? 'Active' : 'Inactive' }} ·
                            {{ p.is_default ? 'Default' : 'Alternative' }}
                        </td>
                        <td class="p-3">
                            <button
                                v-if="p.is_active && can('commissions.manage')"
                                class="underline"
                                @click="deactivate(p.id)"
                            >
                                Deactivate
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!policies.data.length" class="p-5">
                No commission policies configured.
            </p>
        </div>
        <nav aria-label="Pagination" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in policies.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2"
                    ><span v-html="link.label" /></Link
                ><span v-else class="px-3 py-2" v-html="link.label"
            /></template>
        </nav>
    </div>
</template>
