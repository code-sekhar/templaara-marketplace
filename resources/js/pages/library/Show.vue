<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { index, download } from '@/routes/library';
import { show as orderShow } from '@/routes/commerce/orders';
import { formatPrice } from '@/lib/marketplace';
import type { PurchasedSoftware } from '@/types/library';
const props = defineProps<{ purchase: PurchasedSoftware }>();
const copyState = ref('');
const activationForm = useForm({});
import { deactivate } from '@/routes/library/activations';
async function copyLicense() {
    try {
        await navigator.clipboard.writeText(props.purchase.license?.key ?? '');
        copyState.value = 'License copied.';
    } catch {
        copyState.value = 'Select the license key to copy it.';
    }
}
</script>
<template>
    <Head :title="`${purchase.product_name} — Purchased Software`" />
    <div role="main" class="space-y-8 p-4 sm:p-6">
        <Link :href="index()" class="text-sm underline">My Library</Link>
        <Link
            v-if="purchase.support_entry.url"
            :href="purchase.support_entry.url"
            class="inline-block rounded border p-3"
            >Get Support</Link
        >
        <Link
            v-if="purchase.review_entry.url"
            :href="purchase.review_entry.url"
            class="inline-block underline"
            >{{ purchase.review_entry.label }}</Link
        >
        <header class="space-y-3">
            <h1 class="text-3xl font-semibold break-words">
                {{ purchase.product_name }}
            </h1>
            <p>{{ purchase.offer_name }} · Version {{ purchase.version }}</p>
            <p class="text-sm capitalize">
                Software access:
                {{
                    purchase.refund?.status === 'completed'
                        ? 'Refunded'
                        : purchase.status
                }}
            </p>
            <p
                v-if="purchase.refund?.status === 'completed'"
                class="rounded border p-4"
            >
                Previous installation records are retained as history. They
                cannot validate or access this refunded license.
            </p>
        </header>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="text-xl font-semibold">Your purchase</h2>
                <p
                    v-if="purchase.offer_description"
                    class="whitespace-pre-line"
                >
                    {{ purchase.offer_description }}
                </p>
                <p class="capitalize">
                    License type:
                    {{ purchase.license_type.replaceAll('_', ' ') }}
                </p>
                <p>
                    Support:
                    {{
                        purchase.support_period_days === null
                            ? 'Not specified'
                            : `${purchase.support_period_days} days`
                    }}
                </p>
                <p>
                    Updates:
                    {{
                        purchase.updates_period_days === null
                            ? 'Not specified'
                            : `${purchase.updates_period_days} days`
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    This purchase grants the version shown above. Support and
                    update periods do not change license expiration or
                    automatically grant newer releases.
                </p>
                <p>{{ formatPrice(purchase.unit_price, purchase.currency) }}</p>
                <p>
                    Purchased
                    {{ new Date(purchase.granted_at).toLocaleDateString() }}
                </p>
                <Link
                    :href="orderShow(purchase.order_number)"
                    class="inline-block text-sm break-all underline"
                    >Order {{ purchase.order_number }}</Link
                >
            </section>
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="text-xl font-semibold">Download software</h2>
                <template v-if="purchase.download_available"
                    ><a
                        :href="download.url(purchase.reference)"
                        class="inline-block rounded-lg bg-primary px-5 py-3 text-primary-foreground"
                        >Download purchased ZIP</a
                    >
                    <p class="text-sm text-muted-foreground">
                        You can download this purchased version again when
                        needed.
                    </p>
                    <div v-if="purchase.checksum">
                        <p class="text-sm font-medium">
                            SHA-256 package checksum
                        </p>
                        <code class="mt-2 block text-xs break-all select-all">{{
                            purchase.checksum
                        }}</code>
                    </div></template
                >
                <p v-else role="status">
                    {{
                        purchase.status === 'active'
                            ? 'Download temporarily unavailable. Please try again later.'
                            : 'Software access is inactive.'
                    }}
                </p>
            </section>
        </div>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Installations</h2>
            <p>
                {{ purchase.active_activations }} active /
                {{ purchase.activation_limit }} permitted
            </p>
            <p class="text-xs break-all">
                Product identity: {{ purchase.product_identity }}
            </p>
            <p v-if="!purchase.activations.length">
                No installations activated yet.
            </p>
            <article
                v-for="a in purchase.activations"
                :key="a.public_uuid"
                class="space-y-2 rounded-lg border p-4"
            >
                <h3>
                    {{ a.installation_label || 'Installation' }} ·
                    <StatusBadge :status="a.status" />
                </h3>
                <p v-if="a.domain_display">{{ a.domain_display }}</p>
                <p>
                    Activated: {{ new Date(a.activated_at).toLocaleString() }}
                </p>
                <p v-if="a.last_validated_at">
                    Last validated:
                    {{ new Date(a.last_validated_at).toLocaleString() }}
                </p>
                <p v-if="a.deactivated_at">
                    Deactivated:
                    {{ new Date(a.deactivated_at).toLocaleString() }}
                </p>
                <Button
                    v-if="a.status === 'active'"
                    variant="outline"
                    :disabled="activationForm.processing"
                    @click="
                        activationForm.post(
                            deactivate.url([purchase.reference, a.public_uuid]),
                        )
                    "
                    >Deactivate installation</Button
                >
            </article>
        </section>
        <nav
            v-if="
                purchase.activation_pagination &&
                purchase.activation_pagination.last_page > 1
            "
            aria-label="Installation pagination"
            class="flex gap-4"
        >
            <Link
                v-if="purchase.activation_pagination.prev_page_url"
                :href="purchase.activation_pagination.prev_page_url"
                >Previous installations</Link
            ><span
                >Page {{ purchase.activation_pagination.current_page }} of
                {{ purchase.activation_pagination.last_page }}</span
            ><Link
                v-if="purchase.activation_pagination.next_page_url"
                :href="purchase.activation_pagination.next_page_url"
                >Next installations</Link
            >
        </nav>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Your license</h2>
            <template v-if="purchase.license"
                ><p class="capitalize">
                    Status: <StatusBadge :status="purchase.license.status" />
                </p>
                <p v-if="purchase.refund?.status === 'completed'">
                    Refunded · {{ purchase.refund.reference }} ·
                    {{
                        purchase.refund.completed_at
                            ? new Date(
                                  purchase.refund.completed_at,
                              ).toLocaleDateString()
                            : ''
                    }}
                </p>
                <code
                    v-if="purchase.license.key"
                    class="block rounded-lg bg-muted p-4 text-sm break-all select-all"
                    >{{ purchase.license.key }}</code
                ><Button
                    v-if="purchase.license.key"
                    variant="outline"
                    @click="copyLicense"
                    >Copy License Key</Button
                >
                <p v-if="copyState" role="status" class="text-sm">
                    {{ copyState }}
                </p>
                <p class="text-sm">
                    Issued
                    {{
                        new Date(
                            purchase.license.issued_at,
                        ).toLocaleDateString()
                    }}
                </p>
                <p class="text-sm">
                    {{
                        purchase.license.expires_at
                            ? `Expires ${new Date(purchase.license.expires_at).toLocaleDateString()}`
                            : 'No license expiration.'
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Keep your license key private. Activation permits
                    installation use; it does not grant newer releases.
                </p></template
            >
            <p v-else role="status">Your license is being prepared.</p>
        </section>
    </div>
</template>
