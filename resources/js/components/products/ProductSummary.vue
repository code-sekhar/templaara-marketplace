<script setup lang="ts">
import ProductFilesSummary from '@/components/products/ProductFilesSummary.vue';
import { licenseLabels } from '@/lib/products';
import type { ProductRecord } from '@/types/product';
defineProps<{ product: ProductRecord; submissionId?: number }>();
</script>
<template>
    <div class="space-y-5">
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Basic Information</h2>
            <h3 class="text-xl font-semibold">{{ product.name }}</h3>
            <p>{{ product.short_description }}</p>
            <p class="text-sm text-muted-foreground">
                {{ product.category.name }} ·
                {{
                    product.origin === 'templaara'
                        ? 'Templaara Official'
                        : product.owner?.name
                }}
            </p>
            <p class="whitespace-pre-wrap">{{ product.description }}</p>
        </section>
        <section class="space-y-4 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Business Value</h2>
            <h3 class="font-medium">Business problem</h3>
            <p class="whitespace-pre-wrap">{{ product.business_problem }}</p>
            <h3 class="font-medium">Target audience</h3>
            <ul class="list-inside list-disc">
                <li
                    v-for="(item, index) in product.target_audience"
                    :key="index"
                >
                    {{ item }}
                </li>
            </ul>
            <h3 class="font-medium">Key benefits</h3>
            <ul class="list-inside list-disc">
                <li v-for="(item, index) in product.key_benefits" :key="index">
                    {{ item }}
                </li>
            </ul>
        </section>
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Features</h2>
            <article
                v-for="(feature, index) in product.features"
                :key="feature.id ?? index"
            >
                <h3 class="font-medium">{{ feature.title }}</h3>
                <p class="text-sm whitespace-pre-wrap">
                    {{ feature.description }}
                </p>
            </article>
        </section>
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Industries</h2>
            <ul class="list-inside list-disc">
                <li v-for="industry in product.industries" :key="industry.id">
                    {{ industry.name }}
                </li>
            </ul>
        </section>
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Offers / Pricing</h2>
            <article
                v-for="(offer, index) in product.offers"
                :key="offer.id ?? index"
                class="space-y-2 rounded-lg border p-4"
            >
                <h3 class="font-medium">
                    {{ offer.name }} · {{ product.currency }} {{ offer.price }}
                </h3>
                <p class="text-sm whitespace-pre-wrap">
                    {{ offer.description }}
                </p>
                <p class="text-sm">
                    {{ licenseLabels[offer.license_type] }} ·
                    {{ offer.is_active ? 'Active' : 'Inactive'
                    }}{{ offer.is_default ? ' · Default' : '' }}
                </p>
                <p class="text-sm">
                    Support:
                    {{
                        offer.support_period_days === null
                            ? 'Unspecified'
                            : `${offer.support_period_days} days`
                    }}
                    · Updates:
                    {{
                        offer.updates_period_days === null
                            ? 'Unspecified'
                            : `${offer.updates_period_days} days`
                    }}
                </p>
            </article>
        </section>
        <ProductFilesSummary :product="product" :submission-id="submissionId" />
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Demo</h2>
            <a
                v-if="product.demo_url"
                :href="product.demo_url"
                target="_blank"
                rel="noopener noreferrer"
                class="break-all underline"
                >{{ product.demo_url }}</a
            >
            <p v-if="product.demo_username">
                Demo username: {{ product.demo_username }}
            </p>
            <p v-if="product.demo_password">
                Demo password: {{ product.demo_password }}
            </p>
        </section>
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Technical Information</h2>
            <p>
                {{ product.technologies.map((item) => item.name).join(', ') }}
            </p>
            <p v-if="product.version_label">
                Version: {{ product.version_label }}
            </p>
            <a
                v-if="product.documentation_url"
                :href="product.documentation_url"
                target="_blank"
                rel="noopener noreferrer"
                class="break-all underline"
                >Documentation</a
            >
        </section>
        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">SEO</h2>
            <p>{{ product.meta_title || 'No SEO title configured.' }}</p>
            <p>
                {{
                    product.meta_description || 'No SEO description configured.'
                }}
            </p>
        </section>
    </div>
</template>
