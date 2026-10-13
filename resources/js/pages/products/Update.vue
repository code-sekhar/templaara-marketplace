<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useAuthorization } from '@/composables/useAuthorization';
import ProductForm from '@/pages/products/Form.vue';
import ProductSummary from '@/components/products/ProductSummary.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import * as routes from '@/routes/products/updates';
import type {
    ProductRecord,
    ProductContext,
    CatalogOption,
} from '@/types/product';
import type {
    ProductRelease,
    ProductMedia,
    ProductFileLimits,
} from '@/types/product-files';
import type { WorkflowActions } from '@/types/product-review';
const props = defineProps<{
    editor: {
        context: ProductContext;
        product: ProductRecord;
        readOnly: boolean;
        categories: CatalogOption[];
        industries: CatalogOption[];
        technologies: CatalogOption[];
        currencies: string[];
        licenseTypes: string[];
        canFeature: boolean;
        canEditFiles: boolean;
        fileLimits: ProductFileLimits;
        history: null;
        workflowActions: WorkflowActions;
    };
    revision: { id: number; product_id: number; status: string };
    latestSubmissionId: number | null;
    liveVersion: string;
    release: ProductRelease;
    media: ProductMedia[];
    snapshot: ProductRecord | null;
    actions: Record<string, boolean>;
    rounds: {
        data: {
            id: number;
            round_number: number;
            submitted_at: string;
            decision_summary: string | null;
            feedback: { id: number; section: string; message: string }[];
        }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const { can } = useAuthorization();
const params: [number, number] = [props.revision.product_id, props.revision.id];
const releaseForm = useForm({
    version: props.release.version,
    changelog: props.release.changelog,
    release_notes: props.release.release_notes ?? '',
});
const upload = useForm({ kind: 'source_package', file: null as File | null });
const decision = useForm({
    round_id: props.latestSubmissionId,
    summary: '',
    feedback: [] as { section: string; message: string }[],
});
const sections = [
    'general',
    'basic_information',
    'business_details',
    'features',
    'pricing',
    'demo',
    'technical',
    'support',
    'seo',
];
function transition(action: string) {
    const url = {
        submit: routes.submit,
        start: routes.start,
        changes: routes.changes,
        reject: routes.reject,
        approve: routes.approve,
        publish: routes.publish,
    }[action];
    if (url) decision.post(url.url(params), { preserveScroll: true });
}
</script>
<template>
    <Head title="Product version update" />
    <div role="main" class="mx-auto max-w-5xl space-y-6 p-4">
        <header class="space-y-3">
            <h1 class="text-2xl font-semibold">
                {{ editor.product.name }} — Proposed version
                {{ release.version }}
            </h1>
            <p>
                Live version: {{ liveVersion }} · Update:
                <StatusBadge :status="revision.status" />
            </p>
            <p class="text-muted-foreground">
                The published product remains live while this update is
                reviewed.
            </p>
        </header>
        <section
            v-for="round in rounds.data"
            :key="round.id"
            class="space-y-3 rounded-lg border p-4"
        >
            <h2 class="font-semibold">
                Submission round {{ round.round_number }}
            </h2>
            <p>{{ round.decision_summary }}</p>
            <p
                v-for="feedback in round.feedback"
                :key="feedback.id"
                class="rounded-md bg-muted p-3"
            >
                <strong>{{ feedback.section.replaceAll('_', ' ') }}:</strong>
                {{ feedback.message }}
            </p>
        </section>
        <nav class="flex gap-4">
            <Link v-if="rounds.prev_page_url" :href="rounds.prev_page_url"
                >Previous rounds</Link
            ><Link v-if="rounds.next_page_url" :href="rounds.next_page_url"
                >Earlier rounds</Link
            >
        </nav>
        <template v-if="actions.edit"
            ><ProductForm v-bind="editor" :save-url="routes.save.url(params)" />
            <form
                class="space-y-3 rounded-lg border p-5"
                @submit.prevent="releaseForm.patch(routes.release.url(params))"
            >
                <h2 class="text-xl font-semibold">Proposed release</h2>
                <Label for="update-version">Version</Label
                ><Input
                    id="update-version"
                    v-model="releaseForm.version"
                /><Label for="update-changelog">Changelog</Label
                ><textarea
                    id="update-changelog"
                    v-model="releaseForm.changelog"
                    class="w-full rounded-md border p-3"
                    rows="5"
                /><Label for="update-notes">Release notes</Label
                ><textarea
                    id="update-notes"
                    v-model="releaseForm.release_notes"
                    class="w-full rounded-md border p-3"
                />
                <p
                    v-for="(error, key) in releaseForm.errors"
                    :key="key"
                    class="text-destructive"
                >
                    {{ error }}
                </p>
                <Button :disabled="releaseForm.processing">Save release</Button>
            </form>
            <section class="space-y-4 rounded-lg border p-5">
                <h2 class="text-xl font-semibold">
                    Proposed media and private files
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <article v-for="image in media" :key="image.id">
                        <img
                            :src="image.url ?? ''"
                            :alt="image.alt_text || editor.product.name"
                            class="h-40 object-contain"
                        />
                        <p>{{ image.type }}</p>
                        <Button
                            variant="outline"
                            @click="
                                upload.delete(
                                    routes.remove.url([...params, image.id]),
                                )
                            "
                            >Remove from proposal</Button
                        >
                    </article>
                </div>
                <p v-for="file in release.artifacts" :key="file.id">
                    {{ file.type }}: {{ file.original_name }}
                </p>
                <form
                    class="space-y-3"
                    @submit.prevent="
                        upload.post(routes.upload.url(params), {
                            forceFormData: true,
                        })
                    "
                >
                    <Label for="update-file-kind">File type</Label
                    ><select
                        id="update-file-kind"
                        v-model="upload.kind"
                        class="rounded-md border p-2"
                    >
                        <option value="cover">Cover</option>
                        <option value="screenshot">Screenshot</option>
                        <option value="source_package">New source ZIP</option>
                        <option value="documentation">
                            Documentation
                        </option></select
                    ><input
                        aria-label="Proposed file"
                        type="file"
                        required
                        @change="
                            upload.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p
                        v-for="(error, key) in upload.errors"
                        :key="key"
                        class="text-destructive"
                    >
                        {{ error }}
                    </p>
                    <Button :disabled="upload.processing"
                        >Upload proposed file</Button
                    >
                </form>
            </section></template
        >
        <ProductSummary
            v-else-if="snapshot"
            :product="snapshot"
            :submission-id="
                can('products.review')
                    ? (latestSubmissionId ?? undefined)
                    : undefined
            "
        />
        <section class="space-y-4 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Workflow</h2>
            <p
                v-for="(error, key) in decision.errors"
                :key="key"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <template v-if="actions.changes || actions.reject"
                ><Label for="update-summary"
                    >Decision summary / rejection reason</Label
                ><textarea
                    id="update-summary"
                    v-model="decision.summary"
                    class="w-full rounded-md border p-3"
                />
                <div
                    v-for="(feedback, index) in decision.feedback"
                    :key="index"
                    class="flex flex-wrap gap-3"
                >
                    <select
                        v-model="feedback.section"
                        aria-label="Feedback section"
                        class="rounded-md border p-2"
                    >
                        <option
                            v-for="section in sections"
                            :key="section"
                            :value="section"
                        >
                            {{ section.replaceAll('_', ' ') }}
                        </option></select
                    ><textarea
                        v-model="feedback.message"
                        aria-label="Feedback message"
                        class="grow rounded-md border p-2"
                    /><Button
                        variant="outline"
                        @click="decision.feedback.splice(index, 1)"
                        >Remove</Button
                    >
                </div>
                <Button
                    v-if="actions.changes"
                    variant="outline"
                    @click="
                        decision.feedback.push({
                            section: 'general',
                            message: '',
                        })
                    "
                    >Add Feedback Item</Button
                ></template
            >
            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="actions.submit"
                    :disabled="decision.processing"
                    @click="transition('submit')"
                    >{{
                        revision.status === 'changes_requested'
                            ? 'Resubmit Update'
                            : 'Submit Update'
                    }}</Button
                ><Button
                    v-if="actions.start"
                    :disabled="decision.processing"
                    @click="transition('start')"
                    >Start Review</Button
                ><Button
                    v-if="actions.changes"
                    :disabled="decision.processing"
                    @click="transition('changes')"
                    >Request Changes</Button
                ><Button
                    v-if="actions.reject"
                    variant="destructive"
                    :disabled="decision.processing"
                    @click="transition('reject')"
                    >Reject</Button
                ><Button
                    v-if="actions.approve"
                    :disabled="decision.processing"
                    @click="transition('approve')"
                    >Approve</Button
                ><Button
                    v-if="actions.publish"
                    :disabled="decision.processing"
                    @click="transition('publish')"
                    >Publish New Version</Button
                >
            </div>
        </section>
    </div>
</template>
