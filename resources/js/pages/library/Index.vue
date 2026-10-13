<script setup lang="ts">
import PageHeader from '@/components/design/PageHeader.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { index as browse } from '@/routes/marketplace/products';
import { show, download } from '@/routes/library';
import type { LibraryPurchase } from '@/types/library';
defineProps<{
    admin: boolean;
    records: {
        data: LibraryPurchase[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
</script>
<template>
    <Head :title="admin ? 'Purchase Entitlements' : 'My Library'" />
    <div
        role="main"
        class="space-y-6 p-4 sm:p-6"
        :class="{ 'customer-workspace': !admin }"
    >
        <PageHeader
            :title="admin ? 'Purchase Entitlements' : 'My Library'"
            :description="
                admin
                    ? 'Retained purchase access, licenses and download activity.'
                    : 'Your purchased business software, licenses and packages.'
            "
            eyebrow="Your workspace"
        />
        <div
            v-if="records.data.length"
            class="grid gap-6 md:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="purchase in records.data"
                :key="purchase.reference"
                class="library-software-card min-w-0 overflow-hidden rounded-xl border"
            >
                <img
                    v-if="purchase.cover"
                    :src="purchase.cover.url"
                    :alt="purchase.product_name"
                    class="aspect-video w-full bg-muted object-contain"
                    loading="lazy"
                />
                <div class="space-y-3 p-5">
                    <h2 class="text-xl font-semibold break-words">
                        {{ purchase.product_name }}
                    </h2>
                    <p>Package: {{ purchase.offer_name }}</p>
                    <p>Version {{ purchase.version }}</p>
                    <p class="capitalize">
                        License:
                        {{ purchase.license_type.replaceAll('_', ' ') }}
                    </p>
                    <p class="text-sm">
                        Purchased
                        {{ new Date(purchase.granted_at).toLocaleDateString() }}
                    </p>
                    <p class="text-sm capitalize">
                        Software access:
                        {{
                            purchase.refund?.status === 'completed'
                                ? 'Refunded'
                                : purchase.status
                        }}
                        · License:
                        {{ purchase.license_status ?? 'Preparing' }}
                    </p>
                    <p class="font-mono text-xs break-all">
                        {{ purchase.order_number }}
                    </p>
                    <template v-if="admin"
                        ><p>Customer: {{ purchase.customer_name }}</p>
                        <p>
                            {{ purchase.active_activations }} active /
                            {{ purchase.activation_limit }} installations
                        </p>
                        <p
                            v-for="a in purchase.activations"
                            :key="a.public_uuid"
                            class="text-sm"
                        >
                            {{ a.installation_label || 'Installation' }} ·
                            {{ a.domain_display }} ·
                            <StatusBadge :status="a.status" /> · Activated
                            {{ new Date(a.activated_at).toLocaleString()
                            }}<template v-if="a.last_validated_at">
                                · Last validated
                                {{
                                    new Date(
                                        a.last_validated_at,
                                    ).toLocaleString()
                                }}</template
                            >
                        </p>
                        <p>
                            Prepared downloads: {{ purchase.access_count }}
                        </p></template
                    >
                    <p v-if="purchase.refund?.status === 'completed'">
                        Refunded · {{ purchase.refund.reference }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Link
                            v-if="purchase.support_entry.url"
                            :href="purchase.support_entry.url"
                            class="inline-block rounded border p-3"
                            >Get Support</Link
                        >
                        <Link
                            v-if="!admin && purchase.review_entry.url"
                            :href="purchase.review_entry.url"
                            class="inline-block underline"
                            >{{ purchase.review_entry.label }}</Link
                        >
                    </div>
                    <div v-if="!admin" class="flex flex-wrap gap-4 pt-3">
                        <Link
                            :href="show(purchase.reference)"
                            class="rounded-lg border px-4 py-2 text-sm"
                            >View Purchase &amp; License</Link
                        >
                        <a
                            v-if="purchase.status === 'active'"
                            :href="download.url(purchase.reference)"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground"
                            >Download</a
                        >
                    </div>
                </div>
            </article>
        </div>
        <EmptyState
            v-else
            :title="
                admin
                    ? 'No purchase entitlements yet.'
                    : 'Your software workspace starts here'
            "
            :description="
                admin
                    ? 'Retained purchase entitlements will appear here.'
                    : 'Purchased software, release details and authorized license/download actions will appear here.'
            "
            ><Link v-if="!admin" :href="browse()" class="workspace-empty-action"
                >Browse Software →</Link
            ></EmptyState
        >
        <nav
            v-if="records.last_page > 1"
            aria-label="Library pagination"
            class="flex flex-wrap justify-between gap-4"
        >
            <Link
                v-if="records.prev_page_url"
                :href="records.prev_page_url"
                class="underline"
                >Previous</Link
            ><span
                >Page {{ records.current_page }} of
                {{ records.last_page }}</span
            ><Link
                v-if="records.next_page_url"
                :href="records.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
