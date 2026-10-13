<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import ProductUpdateLinks from '@/components/products/ProductUpdateLinks.vue';
import InputError from '@/components/InputError.vue';
import ReviewHistory from '@/components/products/ReviewHistory.vue';
import ProductFilesEditor from '@/components/products/ProductFilesEditor.vue';
import type { ProductFileLimits } from '@/types/product-files';
import ProductSummary from '@/components/products/ProductSummary.vue';
import * as adminProductRoutes from '@/routes/admin/products';
import type {
    ReviewHistory as ReviewHistoryType,
    WorkflowActions,
} from '@/types/product-review';
import ProductField from '@/components/products/ProductField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAuthorization } from '@/composables/useAuthorization';
import { licenseLabels, productRoutes, statusLabels } from '@/lib/products';
import type {
    CatalogOption,
    ProductContext,
    ProductOffer,
    ProductRecord,
} from '@/types/product';

const props = defineProps<{
    context: ProductContext;
    product: ProductRecord | null;
    readOnly: boolean;
    categories: CatalogOption[];
    industries: CatalogOption[];
    technologies: CatalogOption[];
    currencies: string[];
    licenseTypes: string[];
    canFeature: boolean;
    history: ReviewHistoryType | null;
    workflowActions: WorkflowActions;
    canEditFiles: boolean;
    fileLimits: ProductFileLimits;
    saveUrl?: string;
    updates?: { id: number; status: string; version: string }[];
    canCreateUpdate?: boolean;
}>();
const routes = computed(() => productRoutes[props.context]);
const title = computed(() =>
    props.readOnly
        ? 'View product'
        : props.product
          ? 'Edit product'
          : props.context === 'admin'
            ? 'Create official draft product'
            : 'Create draft product',
);
const { can } = useAuthorization();
function newOffer(isDefault = false) {
    return {
        activation_limit: 1,
        name: '',
        description: '',
        price: '0.00',
        license_type: 'single_business',
        support_period_days: '' as string | number,
        updates_period_days: '' as string | number,
        is_default: isDefault,
        is_active: true,
        sort_order: 0,
        id: undefined as number | undefined,
    };
}
function offerData(offer: ProductOffer) {
    return {
        activation_limit: offer.activation_limit ?? 1,
        id: offer.id,
        name: offer.name,
        price: offer.price,
        license_type: offer.license_type,
        is_default: offer.is_default,
        is_active: offer.is_active,
        sort_order: offer.sort_order,
        description: offer.description ?? '',
        support_period_days: offer.support_period_days ?? '',
        updates_period_days: offer.updates_period_days ?? '',
    };
}
function editorData(product: ProductRecord | null) {
    return {
        name: product?.name ?? '',
        slug: product?.slug ?? '',
        category_id: product?.category_id ?? ('' as number | string),
        short_description: product?.short_description ?? '',
        description: product?.description ?? '',
        business_problem: product?.business_problem ?? '',
        target_audience: [...(product?.target_audience ?? [])],
        key_benefits: [...(product?.key_benefits ?? [])],
        industry_ids: product?.industries.map((item) => item.id) ?? [],
        technology_ids: product?.technologies.map((item) => item.id) ?? [],
        features:
            product?.features.map((feature) => ({
                id: feature.id,
                title: feature.title,
                sort_order: feature.sort_order,
                description: feature.description ?? '',
            })) ?? [],
        offers: product?.offers.length
            ? product.offers.map(offerData)
            : [newOffer(true)],
        currency: product?.currency ?? 'INR',
        demo_url: product?.demo_url ?? '',
        demo_username: product?.demo_username ?? '',
        demo_password: product?.demo_password ?? '',
        version_label: product?.version_label ?? '',
        documentation_url: product?.documentation_url ?? '',
        preview_video_url: product?.preview_video_url ?? '',
        is_active: product?.is_active ?? true,
        is_featured: product?.is_featured ?? false,
        meta_title: product?.meta_title ?? '',
        meta_description: product?.meta_description ?? '',
    };
}
const form = useForm(editorData(props.product));
const workflow = useForm({});
function submitReview() {
    if (props.product) workflow.post(routes.value.submit.url(props.product.id));
}
function publishProduct() {
    if (props.product)
        workflow.post(adminProductRoutes.publish.url(props.product.id));
}
function error(path: string): string | undefined {
    return Object.entries(form.errors).find(([field]) => field === path)?.[1];
}
function chooseDefault(index: number) {
    form.offers.forEach((offer, position) => {
        offer.is_default = position === index;
    });
}
function removeOffer(index: number) {
    form.offers.splice(index, 1);
    if (!form.offers.some((offer) => offer.is_default) && form.offers.length)
        chooseDefault(0);
}
function submit() {
    form.transform((data) => {
        const { is_featured, ...fields } = data;
        return props.canFeature ? { ...fields, is_featured } : fields;
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(editorData(props.product));
            form.reset();
        },
    };
    if (props.saveUrl) form.put(props.saveUrl, options);
    else if (props.product)
        form.put(routes.value.update.url(props.product.id), options);
    else form.post(routes.value.store.url(), options);
}
</script>
<template>
    <Head :title="title" />
    <ProductUpdateLinks
        v-if="product && product.status === 'published' && !saveUrl"
        :product-id="product.id"
        :can-create="canCreateUpdate ?? false"
        :updates="updates ?? []"
    />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap justify-between gap-3">
            <Heading
                :title="title"
                description="Start with the business value. You can refine optional details as your product develops."
            /><Button variant="outline" as-child
                ><Link :href="routes.index()">Back to products</Link></Button
            >
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                product?.origin === 'templaara' ||
                (!product && context === 'admin')
                    ? 'Templaara Official'
                    : 'Author product'
            }}
            · {{ statusLabels[product?.status ?? 'draft']
            }}<span v-if="product?.owner"> · {{ product.owner.name }}</span>
        </p>
        <div
            v-if="form.hasErrors"
            role="alert"
            class="rounded-lg border border-destructive p-4 text-sm text-destructive"
        >
            <p class="font-medium">Please check the highlighted fields.</p>
            <ul class="mt-2 list-inside list-disc">
                <li v-for="(message, field) in form.errors" :key="field">
                    {{ message }}
                </li>
            </ul>
        </div>
        <ReviewHistory
            v-if="product && history"
            :history="history"
            :status="product.status"
        />
        <div v-if="readOnly" class="space-y-3">
            <div
                v-if="workflow.hasErrors"
                role="alert"
                class="rounded-lg border border-destructive p-4 text-destructive"
            >
                <p v-for="(message, key) in workflow.errors" :key="key">
                    {{ message }}
                </p>
            </div>
            <Button
                v-if="workflowActions.submit && can('products.submit')"
                :disabled="workflow.processing"
                @click="submitReview"
                >{{
                    product?.status === 'changes_requested'
                        ? 'Resubmit for Review'
                        : 'Submit for Review'
                }}</Button
            >
            <Button
                v-if="workflowActions.publish && can('products.publish')"
                :disabled="workflow.processing"
                @click="publishProduct"
                >Publish</Button
            >
            <ProductSummary v-if="product" :product="product" />
        </div>
        <form v-else class="space-y-6" @submit.prevent="submit">
            <fieldset :disabled="readOnly || form.processing" class="space-y-6">
                <ProductField
                    id="preview_video_url"
                    label="Preview video URL (optional)"
                    :error="error('preview_video_url')"
                    ><Input
                        id="preview_video_url"
                        v-model="form.preview_video_url"
                        type="url"
                        maxlength="2048"
                /></ProductField>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Basic information</h2>
                    <ProductField
                        id="name"
                        label="Product name"
                        :error="error('name')"
                        ><Input
                            id="name"
                            v-model="form.name"
                            maxlength="255"
                            required
                            type="text"
                    /></ProductField>
                    <ProductField
                        id="slug"
                        label="Slug"
                        :error="error('slug')"
                        help="Leave blank to generate from the product name. Use lowercase letters, numbers and hyphens."
                        ><Input
                            id="slug"
                            v-model="form.slug"
                            maxlength="200"
                            type="text"
                    /></ProductField>
                    <ProductField
                        id="category_id"
                        label="Primary category"
                        :error="error('category_id')"
                        ><select
                            id="category_id"
                            v-model="form.category_id"
                            required
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">
                                Choose what this software does
                            </option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name
                                }}{{
                                    category.is_active
                                        ? ''
                                        : ' (inactive, retained)'
                                }}
                            </option>
                        </select></ProductField
                    >
                    <ProductField
                        id="short_description"
                        label="Short description"
                        :error="error('short_description')"
                    >
                        <textarea
                            id="short_description"
                            v-model="form.short_description"
                            maxlength="500"
                            required
                            rows="4"
                            class="rounded-md border bg-background p-3 text-sm"
                        />
                    </ProductField>
                    <ProductField
                        id="description"
                        label="Full description"
                        :error="error('description')"
                    >
                        <textarea
                            id="description"
                            v-model="form.description"
                            maxlength="30000"
                            rows="4"
                            class="rounded-md border bg-background p-3 text-sm"
                        />
                    </ProductField>
                    <Label for="is_active" class="flex items-center gap-2"
                        ><input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4"
                        />Active draft</Label
                    ><InputError :message="error('is_active')" />
                    <template v-if="canFeature"
                        ><Label
                            for="is_featured"
                            class="flex items-center gap-2"
                            ><input
                                id="is_featured"
                                v-model="form.is_featured"
                                type="checkbox"
                                class="size-4"
                            />Featured (for future display)</Label
                        ><InputError :message="error('is_featured')"
                    /></template>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Business details</h2>
                    <ProductField
                        id="business_problem"
                        label="What business problem does this solve?"
                        :error="error('business_problem')"
                    >
                        <textarea
                            id="business_problem"
                            v-model="form.business_problem"
                            maxlength="10000"
                            rows="4"
                            class="rounded-md border bg-background p-3 text-sm"
                        />
                    </ProductField>
                    <div class="space-y-3">
                        <h3 class="font-medium">
                            Target businesses / Who this is for
                        </h3>
                        <InputError :message="error('target_audience')" />
                        <div
                            v-for="(_, index) in form.target_audience"
                            :key="index"
                            class="flex items-start gap-2"
                        >
                            <ProductField
                                :id="`target_audience-${index}`"
                                :label="`Item ${index + 1}`"
                                :error="error(`target_audience.${index}`)"
                                class="flex-1"
                                ><Input
                                    :id="`target_audience-${index}`"
                                    v-model="form.target_audience[index]"
                                    maxlength="500" /></ProductField
                            ><Button
                                type="button"
                                variant="outline"
                                class="mt-6"
                                :aria-label="`Remove item ${index + 1}`"
                                @click="form.target_audience.splice(index, 1)"
                                >Remove</Button
                            >
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.target_audience.length >= 20"
                            @click="form.target_audience.push('')"
                            >Add item</Button
                        >
                    </div>
                    <div class="space-y-3">
                        <h3 class="font-medium">Key benefits</h3>
                        <InputError :message="error('key_benefits')" />
                        <div
                            v-for="(_, index) in form.key_benefits"
                            :key="index"
                            class="flex items-start gap-2"
                        >
                            <ProductField
                                :id="`key_benefits-${index}`"
                                :label="`Item ${index + 1}`"
                                :error="error(`key_benefits.${index}`)"
                                class="flex-1"
                                ><Input
                                    :id="`key_benefits-${index}`"
                                    v-model="form.key_benefits[index]"
                                    maxlength="500" /></ProductField
                            ><Button
                                type="button"
                                variant="outline"
                                class="mt-6"
                                :aria-label="`Remove item ${index + 1}`"
                                @click="form.key_benefits.splice(index, 1)"
                                >Remove</Button
                            >
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.key_benefits.length >= 30"
                            @click="form.key_benefits.push('')"
                            >Add item</Button
                        >
                    </div>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Features</h2>
                    <InputError :message="error('features')" />
                    <p
                        v-if="!form.features.length"
                        class="text-sm text-muted-foreground"
                    >
                        Add the capabilities that make this software useful to a
                        business.
                    </p>
                    <div
                        v-for="(feature, index) in form.features"
                        :key="index"
                        class="grid gap-4 rounded-lg border p-4 md:grid-cols-2"
                    >
                        <ProductField
                            :id="`feature-title-${index}`"
                            label="Feature title"
                            :error="error(`features.${index}.title`)"
                            ><Input
                                :id="`feature-title-${index}`"
                                v-model="feature.title"
                                maxlength="255"
                                required
                        /></ProductField>
                        <ProductField
                            :id="`feature-order-${index}`"
                            label="Sort order"
                            :error="error(`features.${index}.sort_order`)"
                            ><Input
                                :id="`feature-order-${index}`"
                                v-model="feature.sort_order"
                                type="number"
                                min="0"
                                max="1000000"
                                required
                        /></ProductField>
                        <ProductField
                            :id="`feature-description-${index}`"
                            label="Feature description"
                            :error="error(`features.${index}.description`)"
                            class="md:col-span-2"
                        >
                            <textarea
                                :id="`feature-description-${index}`"
                                v-model="feature.description"
                                rows="2"
                                maxlength="5000"
                                class="rounded-md border bg-background p-3 text-sm"
                            /></ProductField
                        ><InputError
                            :message="error(`features.${index}.id`)"
                        /><Button
                            type="button"
                            variant="outline"
                            @click="form.features.splice(index, 1)"
                            >Remove feature</Button
                        >
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.features.length >= 50"
                        @click="
                            form.features.push({
                                id: undefined,
                                title: '',
                                description: '',
                                sort_order: form.features.length * 10,
                            })
                        "
                        >Add feature</Button
                    >
                </section>
                <section class="space-y-4 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Industries</h2>
                    <p class="text-sm text-muted-foreground">
                        Choose the business sectors this software serves.
                    </p>
                    <InputError :message="error('industry_ids')" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Label
                            v-for="industry in industries"
                            :key="industry.id"
                            :for="`industry-${industry.id}`"
                            class="flex items-center gap-2"
                            ><input
                                :id="`industry-${industry.id}`"
                                v-model="form.industry_ids"
                                type="checkbox"
                                :value="industry.id"
                                class="size-4"
                            />{{ industry.name
                            }}{{
                                industry.is_active
                                    ? ''
                                    : ' (inactive, retained)'
                            }}</Label
                        >
                    </div>
                    <p
                        v-if="!industries.length"
                        class="text-sm text-muted-foreground"
                    >
                        No active industries are available.
                    </p>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Pricing / Offers</h2>
                    <p class="text-sm text-muted-foreground">
                        Add purchase options and choose one active default
                        offer. All offers use the product currency.
                    </p>
                    <ProductField
                        id="currency"
                        label="Currency"
                        :error="error('currency')"
                        ><select
                            id="currency"
                            v-model="form.currency"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="currency in currencies"
                                :key="currency"
                                :value="currency"
                            >
                                {{ currency }}
                            </option>
                        </select></ProductField
                    ><InputError :message="error('offers')" />
                    <div
                        v-for="(offer, index) in form.offers"
                        :key="index"
                        class="space-y-4 rounded-lg border p-4"
                    >
                        <h3 class="font-medium">Offer {{ index + 1 }}</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <ProductField
                                :id="`offer-name-${index}`"
                                label="Offer name"
                                :error="error(`offers.${index}.name`)"
                                ><Input
                                    :id="`offer-name-${index}`"
                                    v-model="offer.name"
                                    maxlength="255"
                                    required
                            /></ProductField>
                            <ProductField
                                :id="`offer-price-${index}`"
                                :label="`Price (${form.currency})`"
                                :error="error(`offers.${index}.price`)"
                                ><Input
                                    :id="`offer-price-${index}`"
                                    v-model="offer.price"
                                    inputmode="decimal"
                                    placeholder="4999.00"
                                    required
                            /></ProductField>
                            <ProductField
                                :id="`offer-license-${index}`"
                                label="License type"
                                :error="error(`offers.${index}.license_type`)"
                                ><select
                                    :id="`offer-license-${index}`"
                                    v-model="offer.license_type"
                                    class="h-9 rounded-md border bg-background px-3 text-sm"
                                >
                                    <option
                                        v-for="license in licenseTypes"
                                        :key="license"
                                        :value="license"
                                    >
                                        {{ licenseLabels[license] ?? license }}
                                    </option>
                                </select></ProductField
                            >
                            <ProductField
                                :id="`offer-order-${index}`"
                                label="Sort order"
                                :error="error(`offers.${index}.sort_order`)"
                                ><Input
                                    :id="`offer-order-${index}`"
                                    v-model="offer.sort_order"
                                    type="number"
                                    min="0"
                                    max="1000000"
                                    required
                            /></ProductField>
                            <ProductField
                                :id="`offer-description-${index}`"
                                label="Offer description"
                                :error="error(`offers.${index}.description`)"
                                class="md:col-span-2"
                            >
                                <textarea
                                    :id="`offer-description-${index}`"
                                    v-model="offer.description"
                                    rows="2"
                                    maxlength="5000"
                                    class="rounded-md border bg-background p-3 text-sm"
                                />
                            </ProductField>
                        </div>
                        <div class="flex flex-wrap gap-4">
                            <Label
                                :for="`offer-default-${index}`"
                                class="flex items-center gap-2"
                                ><input
                                    :id="`offer-default-${index}`"
                                    type="radio"
                                    name="default-offer"
                                    :checked="offer.is_default"
                                    @change="chooseDefault(index)"
                                />Default offer</Label
                            ><Label
                                :for="`offer-active-${index}`"
                                class="flex items-center gap-2"
                                ><input
                                    :id="`offer-active-${index}`"
                                    v-model="offer.is_active"
                                    type="checkbox"
                                />Active offer</Label
                            >
                        </div>
                        <InputError
                            :message="error(`offers.${index}.id`)"
                        /><InputError
                            :message="error(`offers.${index}.is_default`)"
                        /><InputError
                            :message="error(`offers.${index}.is_active`)"
                        /><Button
                            type="button"
                            variant="outline"
                            :disabled="form.offers.length <= 1"
                            @click="removeOffer(index)"
                            >Remove offer</Button
                        >
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.offers.length >= 20"
                        @click="
                            form.offers.push({
                                ...newOffer(),
                                sort_order: form.offers.length * 10,
                            })
                        "
                        >Add offer</Button
                    >
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Installation limits</h2>
                    <ProductField
                        v-for="(offer, index) in form.offers"
                        :key="index"
                        :id="`limit-${index}`"
                        :label="`${offer.name || 'Offer'} activation limit`"
                        :error="error(`offers.${index}.activation_limit`)"
                        ><Input
                            :id="`limit-${index}`"
                            v-model="offer.activation_limit"
                            type="number"
                            min="1"
                            max="1000"
                    /></ProductField>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Demo</h2>
                    <p class="text-sm text-muted-foreground">
                        Use dedicated demo credentials intended to be shared
                        with future customers.
                    </p>
                    <ProductField
                        id="demo_url"
                        label="Live demo URL"
                        :error="error('demo_url')"
                        ><Input
                            id="demo_url"
                            v-model="form.demo_url"
                            maxlength="2048"
                            type="url"
                    /></ProductField>
                    <ProductField
                        id="demo_username"
                        label="Demo username"
                        :error="error('demo_username')"
                        ><Input
                            id="demo_username"
                            v-model="form.demo_username"
                            maxlength="255"
                            type="text"
                    /></ProductField>
                    <ProductField
                        id="demo_password"
                        label="Demo password"
                        :error="error('demo_password')"
                        ><Input
                            id="demo_password"
                            v-model="form.demo_password"
                            maxlength="255"
                            type="text"
                    /></ProductField>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Technical information</h2>
                    <p class="text-sm text-muted-foreground">
                        Secondary details for deployment and technical
                        evaluation.
                    </p>
                    <h3 class="font-medium">Technologies</h3>
                    <InputError :message="error('technology_ids')" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Label
                            v-for="technology in technologies"
                            :key="technology.id"
                            :for="`technology-${technology.id}`"
                            class="flex items-center gap-2"
                            ><input
                                :id="`technology-${technology.id}`"
                                v-model="form.technology_ids"
                                type="checkbox"
                                :value="technology.id"
                                class="size-4"
                            />{{ technology.name
                            }}{{
                                technology.is_active
                                    ? ''
                                    : ' (inactive, retained)'
                            }}</Label
                        >
                    </div>
                    <ProductField
                        id="version_label"
                        label="Version label"
                        :error="error('version_label')"
                        ><Input
                            id="version_label"
                            v-model="form.version_label"
                            maxlength="100"
                            type="text"
                    /></ProductField>
                    <ProductField
                        id="documentation_url"
                        label="Documentation URL"
                        :error="error('documentation_url')"
                        ><Input
                            id="documentation_url"
                            v-model="form.documentation_url"
                            maxlength="2048"
                            type="url"
                    /></ProductField>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">Support &amp; Updates</h2>
                    <p class="text-sm text-muted-foreground">
                        Configure periods for each offer. Leave blank if not yet
                        specified; zero explicitly means no included period.
                    </p>
                    <div
                        v-for="(offer, index) in form.offers"
                        :key="index"
                        class="space-y-3 rounded-lg border p-4"
                    >
                        <h3 class="font-medium">
                            {{ offer.name || `Offer ${index + 1}` }}
                        </h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <ProductField
                                :id="`support-${index}`"
                                label="Support period (days)"
                                :error="
                                    error(`offers.${index}.support_period_days`)
                                "
                                ><Input
                                    :id="`support-${index}`"
                                    v-model="offer.support_period_days"
                                    type="number"
                                    min="0"
                                    max="36500" /></ProductField
                            ><ProductField
                                :id="`updates-${index}`"
                                label="Updates period (days)"
                                :error="
                                    error(`offers.${index}.updates_period_days`)
                                "
                                ><Input
                                    :id="`updates-${index}`"
                                    v-model="offer.updates_period_days"
                                    type="number"
                                    min="0"
                                    max="36500"
                            /></ProductField>
                        </div>
                    </div>
                </section>
                <section class="space-y-5 rounded-lg border p-5">
                    <h2 class="text-lg font-semibold">SEO</h2>
                    <ProductField
                        id="meta_title"
                        label="Meta title"
                        :error="error('meta_title')"
                        ><Input
                            id="meta_title"
                            v-model="form.meta_title"
                            maxlength="160"
                            type="text"
                    /></ProductField>
                    <ProductField
                        id="meta_description"
                        label="Meta description"
                        :error="error('meta_description')"
                    >
                        <textarea
                            id="meta_description"
                            v-model="form.meta_description"
                            maxlength="320"
                            rows="4"
                            class="rounded-md border bg-background p-3 text-sm"
                        />
                    </ProductField>
                </section>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="
                        !readOnly &&
                        can(product ? 'products.update' : 'products.create')
                    "
                    :disabled="form.processing"
                    ><Spinner v-if="form.processing" />Save product</Button
                ><Button variant="outline" as-child
                    ><Link :href="routes.index()">{{
                        readOnly ? 'Back' : 'Cancel'
                    }}</Link></Button
                >
            </div>
            <p
                v-if="product && workflowActions.submit"
                class="text-sm text-muted-foreground"
            >
                Save your changes, then
                <Link :href="routes.show(product.id)" class="underline"
                    >open the product review panel</Link
                >
                to submit.
            </p>
        </form>
        <ProductFilesEditor
            v-if="product && !readOnly && canEditFiles"
            :product="product"
            :limits="fileLimits"
        />
        <p v-if="!product" class="text-sm text-muted-foreground">
            Save the draft to add cover images, screenshots and a release
            package.
        </p>
    </div>
</template>
