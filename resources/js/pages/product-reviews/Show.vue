<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ProductSummary from '@/components/products/ProductSummary.vue';
import ReviewHistory from '@/components/products/ReviewHistory.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuthorization } from '@/composables/useAuthorization';
import { feedbackSections, statusLabels } from '@/lib/products';
import * as routes from '@/routes/admin/product-reviews';
import * as products from '@/routes/admin/products';
import type { ProductRecord, ProductStatus } from '@/types/product';
import type {
    SubmissionRound,
    ReviewHistory as History,
    WorkflowActions,
} from '@/types/product-review';
const props = defineProps<{
    submission: SubmissionRound;
    product: ProductRecord;
    currentStatus: ProductStatus;
    history: History;
    actions: WorkflowActions;
}>();
const { can } = useAuthorization();
const form = useForm({
    summary: '',
    feedback: [{ section: 'general', message: '' }],
});
function act(action: 'start' | 'changes' | 'reject' | 'approve' | 'publish') {
    form.transform((data) =>
        action === 'changes' ? data : { summary: data.summary },
    );
    const url =
        action === 'publish'
            ? products.publish.url(props.product.id)
            : routes[action].url(props.submission.id);
    form.post(url, { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>
<template>
    <Head :title="`Review: ${product.name}`" />
    <div role="main" class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap justify-between gap-3">
            <Heading
                :title="product.name"
                :description="`Submission #${submission.round_number} · ${statusLabels[currentStatus]}`"
            /><Button variant="outline" as-child
                ><Link :href="routes.index()"
                    >Back to review queue</Link
                ></Button
            >
        </div>
        <p class="rounded-lg border bg-muted/40 p-4 text-sm">
            This is the frozen content submitted in round #{{
                submission.round_number
            }}. Decisions apply only to the latest claimed round.
        </p>
        <ProductSummary :product="product" :submission-id="submission.id" />
        <section class="space-y-4 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Moderation</h2>
            <div
                v-if="form.hasErrors"
                role="alert"
                class="rounded-lg border border-destructive p-4 text-destructive"
            >
                <p v-for="(message, key) in form.errors" :key="key">
                    {{ message }}
                </p>
            </div>
            <Button
                v-if="actions.start && can('products.review')"
                :disabled="form.processing"
                @click="act('start')"
                >Start Review</Button
            >
            <template
                v-if="actions.changes || actions.reject || actions.approve"
                ><div class="grid gap-2">
                    <Label for="summary"
                        >Decision summary / Rejection reason</Label
                    ><textarea
                        id="summary"
                        v-model="form.summary"
                        rows="3"
                        maxlength="5000"
                        class="rounded-md border bg-background p-3"
                    />
                </div>
                <div v-if="actions.changes" class="space-y-3">
                    <h3 class="font-medium">Requested changes</h3>
                    <div
                        v-for="(item, index) in form.feedback"
                        :key="index"
                        class="grid gap-3 rounded-lg border p-4"
                    >
                        <Label :for="`section-${index}`">Section</Label
                        ><select
                            :id="`section-${index}`"
                            v-model="item.section"
                            class="h-9 rounded-md border bg-background px-3"
                        >
                            <option
                                v-for="(label, value) in feedbackSections"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option></select
                        ><Label :for="`feedback-${index}`"
                            >Actionable feedback</Label
                        ><Input
                            :id="`feedback-${index}`"
                            v-model="item.message"
                            maxlength="5000"
                        /><Button
                            variant="outline"
                            :disabled="form.processing"
                            @click="form.feedback.splice(index, 1)"
                            >Remove Feedback Item</Button
                        >
                    </div>
                    <Button
                        variant="outline"
                        :disabled="
                            form.processing || form.feedback.length >= 30
                        "
                        @click="
                            form.feedback.push({
                                section: 'general',
                                message: '',
                            })
                        "
                        >Add Feedback Item</Button
                    >
                </div>
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-if="actions.changes && can('products.soft-reject')"
                        variant="outline"
                        :disabled="form.processing"
                        @click="act('changes')"
                        >Request Changes</Button
                    ><Button
                        v-if="actions.reject && can('products.reject')"
                        variant="destructive"
                        :disabled="form.processing"
                        @click="act('reject')"
                        >Reject</Button
                    ><Button
                        v-if="actions.approve && can('products.approve')"
                        :disabled="form.processing"
                        @click="act('approve')"
                        >Approve</Button
                    >
                </div>
            </template>
            <Button
                v-if="actions.publish && can('products.publish')"
                :disabled="form.processing"
                @click="act('publish')"
                >Publish</Button
            >
            <p
                v-if="
                    !actions.start &&
                    !actions.changes &&
                    !actions.reject &&
                    !actions.approve &&
                    !actions.publish
                "
                class="text-sm text-muted-foreground"
            >
                No moderation action is available for your account on this
                round. You can inspect its content and history.
            </p>
        </section>
        <ReviewHistory :history="history" :status="currentStatus" />
    </div>
</template>
