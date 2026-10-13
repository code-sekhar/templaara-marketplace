<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import ReviewContent from '@/components/reviews/ReviewContent.vue';
import { useAuthorization } from '@/composables/useAuthorization';
import * as customer from '@/routes/account/reviews';
import * as adminRoutes from '@/routes/admin/reviews';
import * as authorRoutes from '@/routes/author/reviews';
import type { ReviewRow } from '@/types/reviews';
const props = defineProps<{
    product: { name: string; slug: string };
    review: ReviewRow | null;
    admin: boolean;
    author: boolean;
}>();
const { can } = useAuthorization();
const form = useForm({
    rating: props.review?.rating ?? 5,
    title: props.review?.title ?? '',
    body: props.review?.body ?? '',
});
const actionForm = useForm({ reason: '' });
const response = useForm({ body: props.review?.response?.body ?? '' });
const routes = props.admin
    ? adminRoutes
    : props.author
      ? authorRoutes
      : customer;
function save() {
    if (props.review) form.put(customer.update.url(props.review.uuid));
    else form.post(customer.store.url(props.product.slug));
}
function action(a: 'withdraw' | 'restore' | 'hide' | 'unhide') {
    if (!props.review || !window.confirm('Confirm this review action?')) return;
    const url =
        a === 'hide'
            ? adminRoutes.hide.url(props.review.uuid)
            : a === 'unhide'
              ? adminRoutes.unhide.url(props.review.uuid)
              : a === 'withdraw'
                ? customer.withdraw.url(props.review.uuid)
                : customer.restore.url(props.review.uuid);
    actionForm.post(url);
}
function respond() {
    if (!props.review) return;
    const r = props.admin ? adminRoutes : authorRoutes;
    response.post(
        (props.review.response ? r.edit_response : r.respond).url(
            props.review.uuid,
        ),
    );
}
</script>
<template>
    <Head :title="product.name + ' — Your review'" />
    <div role="main" class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
        <Link :href="routes.index()" class="underline">Review history</Link>
        <h1 class="text-2xl font-semibold break-words">{{ product.name }}</h1>
        <template v-if="review"
            ><p class="capitalize">
                <StatusBadge :status="review.status" /><span
                    v-if="review.withdrawn_at"
                >
                    · Withdrawn</span
                >
            </p>
            <p
                v-if="!review.verification_retained || !review.qualified"
                class="rounded border p-4"
            >
                This review is no longer public because there is no active
                qualifying purchase for this product. If you purchase again,
                restore your existing review here.
            </p>
            <p v-if="review.status === 'hidden'" class="rounded border p-4">
                This review has been hidden by moderation. Editing or restoring
                a withdrawn review does not remove this restriction.
            </p>
            <ReviewContent :review="review"
        /></template>
        <form
            v-if="!admin && !author && (!review || review.can_edit)"
            class="space-y-5"
            @submit.prevent="save"
        >
            <label class="block"
                >Rating<select
                    id="review-rating"
                    v-model.number="form.rating"
                    class="block rounded border p-3"
                >
                    <option v-for="n in [5, 4, 3, 2, 1]" :key="n" :value="n">
                        {{ n }} stars
                    </option></select
                ><InputError :message="form.errors.rating" /></label
            ><label class="block"
                >Title (optional)<input
                    id="review-title"
                    v-model="form.title"
                    maxlength="120"
                    class="block w-full rounded border p-3" /><InputError
                    :message="form.errors.title" /></label
            ><label class="block"
                >Your experience<textarea
                    id="review-body"
                    v-model="form.body"
                    rows="7"
                    minlength="20"
                    maxlength="5000"
                    class="block w-full rounded border p-3" /><InputError
                    :message="form.errors.body"
            /></label>
            <p class="text-sm">
                20–5,000 characters. Use plain text and describe your own
                experience.
            </p>
            <InputError
                :message="(form.errors as Record<string, string>).review"
            /><button :disabled="form.processing" class="rounded border p-3">
                {{ review ? 'Save Review' : 'Publish Review' }}
            </button>
        </form>
        <section v-if="review" class="space-y-4">
            <InputError
                :message="(actionForm.errors as Record<string, string>).review"
            />
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="!admin && !author && review.can_withdraw"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('withdraw')"
                >
                    Withdraw Review</button
                ><button
                    v-if="!admin && !author && review.can_restore"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('restore')"
                >
                    Restore Review
                </button>
            </div>
            <template v-if="admin && can('reviews.manage')"
                ><label v-if="review.status !== 'hidden'" class="block"
                    >Moderation reason<textarea
                        v-model="actionForm.reason"
                        class="block w-full rounded border p-3" /><InputError
                        :message="actionForm.errors.reason" /></label
                ><button
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="
                        action(review.status === 'hidden' ? 'unhide' : 'hide')
                    "
                >
                    {{
                        review.status === 'hidden'
                            ? 'Restore Public Review'
                            : 'Hide Review'
                    }}
                </button></template
            >
            <form
                v-if="
                    review.can_respond &&
                    (!review.response || review.can_edit_response)
                "
                class="space-y-4"
                @submit.prevent="respond"
            >
                <h2 class="text-xl">Seller response</h2>
                <label class="block"
                    >Response<textarea
                        id="review-response"
                        v-model="response.body"
                        rows="5"
                        minlength="2"
                        maxlength="3000"
                        class="block w-full rounded border p-3"
                    /></label
                ><InputError :message="response.errors.body" /><InputError
                    :message="
                        (response.errors as Record<string, string>).response
                    "
                /><InputError
                    :message="
                        (response.errors as Record<string, string>).review
                    "
                /><button
                    :disabled="response.processing"
                    class="rounded border p-3"
                >
                    {{ review.response ? 'Save Response' : 'Publish Response' }}
                </button>
            </form>
            <div v-if="review.events" class="space-y-3">
                <h2 class="text-xl">Review history</h2>
                <p v-for="(e, n) in review.events.data" :key="n">
                    {{ e.type.replaceAll('_', ' ') }} ·
                    {{ new Date(e.occurred_at).toLocaleString()
                    }}<span v-if="e.reason" class="block">{{ e.reason }}</span>
                </p>
                <nav class="flex gap-4">
                    <Link
                        v-if="review.events.prev_page_url"
                        :href="review.events.prev_page_url"
                        >Previous history</Link
                    ><Link
                        v-if="review.events.next_page_url"
                        :href="review.events.next_page_url"
                        >More history</Link
                    >
                </nav>
            </div>
        </section>
    </div>
</template>
