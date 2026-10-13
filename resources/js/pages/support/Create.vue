<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import * as routes from '@/routes/account/support';
import type { SupportPage, SupportPurchase } from '@/types/support';
const props = defineProps<{
    purchases: SupportPage<SupportPurchase>;
    requestKey: string;
    attachmentMaxKb: number;
    attachmentsMax: number;
    attachmentTypes: string[];
}>();
const categories = [
    'installation',
    'configuration',
    'bug',
    'usage_question',
    'compatibility',
    'license_activation',
    'update',
    'other',
];
const form = useForm({
    request_key: props.requestKey,
    purchase: props.purchases.data[0]?.purchase ?? '',
    category: 'installation',
    priority: 'normal',
    subject: '',
    body: '',
    attachments: [] as File[],
});
function upload(e: Event) {
    form.attachments = Array.from((e.target as HTMLInputElement).files ?? []);
}
function submit() {
    form.post(routes.store.url(), { forceFormData: true });
}
</script>
<template>
    <Head title="Get Product Support" />
    <main class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
        <Link :href="routes.index()" class="underline">My Support</Link>
        <h1 class="text-3xl font-semibold">Get product support</h1>
        <p>
            Choose your purchased software. Support applies to the purchased
            package and version.
        </p>
        <p v-if="!purchases.data.length" class="rounded border p-5">
            No purchases currently include eligible support. Existing tickets
            remain available in My Support.
        </p>
        <form v-else class="space-y-5" @submit.prevent="submit">
            <label class="block"
                >Purchase<select
                    id="support-purchase"
                    v-model="form.purchase"
                    class="block w-full rounded border p-3"
                >
                    <option
                        v-for="p in purchases.data"
                        :key="p.purchase"
                        :value="p.purchase"
                    >
                        {{ p.product_name }} · {{ p.offer_name }} ·
                        {{ p.version }}
                    </option></select
                ><InputError :message="form.errors.purchase"
            /></label>
            <div
                v-for="p in purchases.data.filter(
                    (p) => p.purchase === form.purchase,
                )"
                :key="p.purchase"
                class="rounded border p-4"
            >
                <p>Purchased version: {{ p.version }}</p>
                <p>Package: {{ p.offer_name }}</p>
                <p>
                    Support until
                    {{ new Date(p.support_ends_at).toLocaleString() }}
                    (exclusive)
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label
                    >Category<select
                        id="support-category"
                        v-model="form.category"
                        class="block w-full rounded border p-3"
                    >
                        <option v-for="c in categories" :key="c" :value="c">
                            {{ c.replaceAll('_', ' ') }}
                        </option></select
                    ><InputError :message="form.errors.category" /></label
                ><label
                    >Priority<select
                        id="support-priority"
                        v-model="form.priority"
                        class="block w-full rounded border p-3"
                    >
                        <option v-for="p in ['low', 'normal', 'high']" :key="p">
                            {{ p }}
                        </option></select
                    ><InputError :message="form.errors.priority"
                /></label>
            </div>
            <label class="block"
                >Subject<input
                    id="support-subject"
                    v-model="form.subject"
                    minlength="3"
                    maxlength="200"
                    required
                    class="block w-full rounded border p-3" /><InputError
                    :message="form.errors.subject" /></label
            ><label class="block"
                >Message<textarea
                    id="support-body"
                    v-model="form.body"
                    required
                    maxlength="10000"
                    rows="7"
                    class="block w-full rounded border p-3" /><InputError
                    :message="form.errors.body" /></label
            ><label class="block"
                >Attachments<input
                    id="support-attachments"
                    type="file"
                    multiple
                    :accept="
                        attachmentTypes.map((type) => `.${type}`).join(',')
                    "
                    class="block max-w-full"
                    @change="upload" /><InputError
                    :message="form.errors.attachments"
            /></label>
            <p class="text-sm">
                Up to {{ attachmentsMax }} private files,
                {{ attachmentMaxKb / 1024 }} MiB each. PNG, JPEG, WebP, PDF, TXT
                or LOG. Do not include passwords, license keys or payment
                information.
            </p>
            <InputError
                v-for="(error, key) in form.errors"
                :key="key"
                :message="error"
            /><button
                :disabled="form.processing"
                class="rounded border px-5 py-3"
            >
                {{ form.processing ? 'Creating…' : 'Open Support Ticket' }}
            </button>
        </form>
        <nav class="flex gap-4">
            <Link v-if="purchases.prev_page_url" :href="purchases.prev_page_url"
                >Previous purchases</Link
            ><Link
                v-if="purchases.next_page_url"
                :href="purchases.next_page_url"
                >More purchases</Link
            >
        </nav>
    </main>
</template>
