<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { formatPrice } from '@/lib/marketplace';
import type { InvoiceDocument } from '@/lib/invoicing';
defineProps<{ document: InvoiceDocument }>();
</script>
<template>
    <Head :title="document.number" />
    <main class="mx-auto max-w-5xl space-y-6 p-4 md:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">
                    {{
                        document.kind === 'credit_note'
                            ? 'Credit note'
                            : 'Invoice'
                    }}
                    {{ document.number }}
                </h1>
                <p class="mt-2">
                    Order {{ document.order_number }} · Issued
                    {{ document.issued_at }} ·
                    {{ document.currency }}
                </p>
            </div>
            <a :href="document.pdf_url" class="rounded-lg border px-4 py-2"
                >Download PDF</a
            >
        </header>
        <p v-if="document.original" class="rounded-lg border p-4">
            Original invoice:
            <Link :href="document.original.url" class="underline">{{
                document.original.number
            }}</Link>
            · Refund {{ document.refund_reference }}
        </p>
        <p
            v-if="document.provenance === 'legacy_undetermined'"
            class="rounded-lg border p-4"
        >
            Historical paid amount preserved. Tax was not historically
            determined; no retrospective GST/VAT is assigned.
        </p>
        <div class="grid gap-6 sm:grid-cols-2">
            <section class="rounded-lg border p-5">
                <h2 class="font-semibold">Issued by</h2>
                <p>{{ document.issuer_snapshot.legal_name }}</p>
                <p>
                    {{ document.issuer_snapshot.address_line_1 }}
                    {{ document.issuer_snapshot.address_line_2 }}
                </p>
                <p>
                    {{ document.issuer_snapshot.city }},
                    {{ document.issuer_snapshot.state_region }}
                    {{ document.issuer_snapshot.postal_code }}
                </p>
                <p>{{ document.issuer_snapshot.country_code }}</p>
                <p>{{ document.issuer_snapshot.contact_email }}</p>
                <p v-if="document.issuer_snapshot.gstin">
                    GSTIN {{ document.issuer_snapshot.gstin }}
                </p>
                <p v-else>
                    {{ document.issuer_snapshot.tax_registration_number }}
                </p>
            </section>
            <section class="rounded-lg border p-5">
                <h2 class="font-semibold">Billed to</h2>
                <p>{{ document.customer_snapshot.customer_name }}</p>
                <p>{{ document.customer_snapshot.company_name }}</p>
                <p>
                    {{ document.customer_snapshot.address_line_1 }}
                    {{ document.customer_snapshot.address_line_2 }}
                </p>
                <p>
                    {{ document.customer_snapshot.city }},
                    {{ document.customer_snapshot.state_region }}
                    {{ document.customer_snapshot.postal_code }}
                </p>
                <p>{{ document.customer_snapshot.country_code }}</p>
                <p>{{ document.customer_snapshot.billing_email }}</p>
                <p>
                    {{
                        document.customer_snapshot.customer_gstin ||
                        document.customer_snapshot.customer_tax_id
                    }}
                </p>
            </section>
        </div>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Software &amp; packages</h2>
            <article
                v-for="(item, index) in document.items"
                :key="index"
                class="rounded-lg border p-5"
            >
                <h3 class="font-semibold">{{ item.product_name }}</h3>
                <p>
                    {{ item.offer_name }} · {{ item.version }} · By
                    {{ item.seller_name }}
                </p>
                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    <p>
                        Taxable
                        {{
                            formatPrice(item.taxable_amount, document.currency)
                        }}
                    </p>
                    <p>
                        Tax
                        {{ formatPrice(item.tax_amount, document.currency) }}
                    </p>
                    <p class="font-medium">
                        Total
                        {{ formatPrice(item.grand_total, document.currency) }}
                    </p>
                </div>
                <p
                    v-for="component in item.components"
                    :key="component.type"
                    class="text-sm"
                >
                    {{ component.type }} {{ component.rate }}% ·
                    {{ formatPrice(component.amount, document.currency) }}
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Jurisdiction {{ item.jurisdiction }}
                    <span v-if="item.service_code"
                        >· HSN/SAC {{ item.service_code }}</span
                    >
                </p>
            </article>
        </section>
        <section class="space-y-2 rounded-lg border p-5">
            <p>
                Taxable amount
                {{ formatPrice(document.taxable_amount, document.currency) }}
            </p>
            <p>Tax {{ formatPrice(document.tax_total, document.currency) }}</p>
            <p class="text-xl font-semibold">
                Total {{ formatPrice(document.grand_total, document.currency) }}
            </p>
            <p>
                {{
                    document.inclusive
                        ? 'Purchased prices include tax.'
                        : 'Tax is added where configured.'
                }}
            </p>
        </section>
        <section v-if="document.credits.length" class="space-y-2">
            <h2 class="text-xl font-semibold">Related credit notes</h2>
            <div v-for="credit in document.credits" :key="credit.public_uuid">
                <Link :href="credit.url" class="underline">{{
                    credit.number
                }}</Link>
                ·
                {{ formatPrice(credit.grand_total, credit.currency) }}
            </div>
        </section>
        <p class="text-sm text-muted-foreground">
            {{ document.issuer_snapshot.customer_footer }}
        </p>
    </main>
</template>
