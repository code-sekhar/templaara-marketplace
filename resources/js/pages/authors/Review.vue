<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthorSnapshot from '@/components/authors/AuthorSnapshot.vue';
import ApplicationHistory from '@/components/authors/ApplicationHistory.vue';
import * as routes from '@/routes/admin/author-applications';
import { useAuthorization } from '@/composables/useAuthorization';
import { authorStatusLabels, feedbackSections } from '@/types/authors';
import type { AuthorRound, AuthorHistory } from '@/types/authors';
const props = defineProps<{
    application: AuthorRound;
    applicant: { name: string; email: string };
    reviewer: { name: string } | null;
    history: AuthorHistory;
    actions: { start: boolean; decide: boolean };
}>();
const { can } = useAuthorization();
const form = useForm({
    summary: '',
    feedback: [{ section: 'general', message: '' }],
});
function act(action: 'start' | 'changes' | 'reject' | 'approve') {
    form.transform((data) =>
        action === 'changes' ? data : { summary: data.summary },
    );
    form.post(routes[action].url(props.application.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head :title="`Author application: ${application.snapshot.display_name}`" />
    <div role="main" class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold">
                Author Application · Round #{{ application.round_number }}
            </h1>
            <p class="mt-2">
                {{ authorStatusLabels[application.status] }} ·
                {{ applicant.name }} · {{ applicant.email }}
            </p>
            <Link :href="routes.index()" class="mt-3 inline-block underline"
                >Back to applications</Link
            >
        </header>
        <p class="rounded-lg border bg-muted/40 p-4">
            This is the frozen submitted profile. Approval grants marketplace
            author access, not KYC verification.
        </p>
        <AuthorSnapshot :content="application.snapshot" />
        <section class="space-y-4 rounded-lg border p-5">
            <h2 class="text-xl font-semibold">Application review</h2>
            <p v-if="reviewer">Reviewer: {{ reviewer.name }}</p>
            <div v-if="form.hasErrors" role="alert" class="text-destructive">
                <p v-for="(message, key) in form.errors" :key="key">
                    {{ message }}
                </p>
            </div>
            <Button
                v-if="actions.start && can('authors.verify')"
                :disabled="form.processing"
                @click="act('start')"
                >Start Review</Button
            >
            <template v-if="actions.decide && can('authors.verify')"
                ><Label for="summary">Decision summary / Rejection reason</Label
                ><textarea
                    id="summary"
                    v-model="form.summary"
                    rows="3"
                    maxlength="5000"
                    class="w-full rounded-md border bg-background p-3"
                />
                <h3 class="font-semibold">Requested changes</h3>
                <div
                    v-for="(item, i) in form.feedback"
                    :key="i"
                    class="grid gap-3 rounded-lg border p-4"
                >
                    <Label :for="`section-${i}`">Section</Label
                    ><select
                        :id="`section-${i}`"
                        v-model="item.section"
                        class="h-10 rounded-md border bg-background px-3"
                    >
                        <option
                            v-for="section in feedbackSections"
                            :key="section"
                            :value="section"
                        >
                            {{ section }}
                        </option></select
                    ><Label :for="`message-${i}`">Actionable feedback</Label
                    ><Input
                        :id="`message-${i}`"
                        v-model="item.message"
                        maxlength="5000"
                    /><Button
                        variant="outline"
                        :disabled="form.processing"
                        @click="form.feedback.splice(i, 1)"
                        >Remove Feedback Item</Button
                    >
                </div>
                <Button
                    variant="outline"
                    :disabled="form.processing || form.feedback.length >= 20"
                    @click="
                        form.feedback.push({ section: 'general', message: '' })
                    "
                    >Add Feedback Item</Button
                >
                <div class="flex flex-wrap gap-3">
                    <Button :disabled="form.processing" @click="act('changes')"
                        >Request Changes</Button
                    ><Button
                        variant="destructive"
                        :disabled="form.processing"
                        @click="act('reject')"
                        >Reject Application</Button
                    ><Button :disabled="form.processing" @click="act('approve')"
                        >Approve Author</Button
                    >
                </div></template
            >
        </section>
        <ApplicationHistory :history="history" />
    </div>
</template>
