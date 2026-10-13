<script setup lang="ts">
import StatusBadge from '@/components/design/StatusBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import * as customer from '@/routes/account/support';
import * as author from '@/routes/author/support';
import * as admin from '@/routes/admin/support';
import type { SupportTicket, SupportMode } from '@/types/support';
const props = defineProps<{
    ticket: SupportTicket;
    mode: SupportMode;
    requestKey: string;
    attachmentMaxKb: number;
    attachmentsMax: number;
    attachmentTypes: string[];
}>();
const routes =
    props.mode === 'admin'
        ? admin
        : props.mode === 'author'
          ? author
          : customer;
const form = useForm({
    request_key: props.requestKey,
    body: '',
    attachments: [] as File[],
});
const internal = ref(false);
const actionForm = useForm({ reason: '', priority: props.ticket.priority });
const uploadInput = ref<HTMLInputElement | null>(null);
watch(
    () => props.requestKey,
    (key) => {
        form.request_key = key;
    },
);
function upload(e: Event) {
    form.attachments = Array.from((e.target as HTMLInputElement).files ?? []);
}
function send() {
    form.post(
        internal.value
            ? admin.notes.url(props.ticket.uuid)
            : routes.messages.url(props.ticket.uuid),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('body', 'attachments');
                if (uploadInput.value) uploadInput.value.value = '';
            },
        },
    );
}
function action(
    a: 'resolve' | 'close' | 'reopen' | 'priority' | 'escalate' | 'deescalate',
) {
    if (!window.confirm('Confirm this support action?')) return;
    const url =
        a === 'resolve'
            ? (props.mode === 'admin' ? admin.resolve : author.resolve).url(
                  props.ticket.uuid,
              )
            : a === 'close'
              ? (props.mode === 'admin' ? admin.close : customer.close).url(
                    props.ticket.uuid,
                )
              : a === 'reopen'
                ? (props.mode === 'admin' ? admin.reopen : customer.reopen).url(
                      props.ticket.uuid,
                  )
                : a === 'priority'
                  ? admin.priority.url(props.ticket.uuid)
                  : a === 'deescalate'
                    ? admin.deescalate.url(props.ticket.uuid)
                    : (props.mode === 'admin'
                          ? admin.escalate
                          : author.escalate
                      ).url(props.ticket.uuid);
    actionForm.post(url, { preserveScroll: true });
}
</script>
<template>
    <Head :title="ticket.subject" />
    <main class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <Link :href="routes.index()" class="underline">Support tickets</Link>
        <p class="text-sm break-words">{{ ticket.ticket_number }}</p>
        <h1 class="text-2xl font-semibold break-words">{{ ticket.subject }}</h1>
        <div class="space-y-2 rounded border p-4">
            <p>
                {{ ticket.product_name }} · Purchased version
                {{ ticket.version }}
            </p>
            <p>
                Package: {{ ticket.offer_name }} ·
                {{ ticket.license_type.replaceAll('_', ' ') }}
            </p>
            <p>
                Support until
                {{ new Date(ticket.support_ends_at).toLocaleString() }}
                (exclusive)
            </p>
            <p class="capitalize">
                <StatusBadge :status="ticket.status" /> ·
                {{ ticket.category.replaceAll('_', ' ') }} ·
                {{ ticket.priority }}
            </p>
            <p>{{ mode === 'author' ? ticket.customer : ticket.seller }}</p>
            <p v-if="ticket.is_escalated">Escalated to Templaara Support</p>
            <p v-if="ticket.admin_locked">
                This ticket is terminal and cannot be reopened.
            </p>
        </div>
        <section class="space-y-4" aria-label="Conversation">
            <h2 class="text-xl font-semibold">Conversation</h2>
            <article
                v-for="m in ticket.messages.data"
                :key="m.public_uuid"
                class="space-y-3 rounded-xl border p-5"
            >
                <p class="font-medium">
                    {{ m.sender
                    }}<span v-if="m.is_internal"> · Internal admin note</span>
                </p>
                <p class="text-xs">
                    {{ new Date(m.created_at).toLocaleString() }}
                </p>
                <p class="break-words whitespace-pre-line">{{ m.body }}</p>
                <a
                    v-for="a in m.attachments"
                    :key="a.public_uuid"
                    :href="a.url"
                    class="block break-words underline"
                    >Download {{ a.original_name }} ({{
                        Math.ceil(a.size_bytes / 1024)
                    }}
                    KiB)</a
                >
            </article>
            <nav class="flex gap-4">
                <Link
                    v-if="ticket.messages.prev_page_url"
                    :href="ticket.messages.prev_page_url"
                    preserve-scroll
                    >Previous messages</Link
                ><Link
                    v-if="ticket.messages.next_page_url"
                    :href="ticket.messages.next_page_url"
                    preserve-scroll
                    >More messages</Link
                >
            </nav>
        </section>
        <form
            v-if="ticket.can_reply || ticket.can_note"
            class="space-y-4"
            @submit.prevent="send"
        >
            <h2 class="text-xl font-semibold">
                {{ internal ? 'Internal admin note' : 'Reply' }}
            </h2>
            <label v-if="ticket.can_note" class="flex gap-2"
                ><input v-model="internal" type="checkbox" />Internal note
                (admin only)</label
            ><label class="block"
                >Message<textarea
                    id="support-reply"
                    v-model="form.body"
                    maxlength="10000"
                    required
                    rows="6"
                    class="block w-full rounded border p-3"
                /></label
            ><label class="block"
                >Attachments<input
                    id="support-attachments"
                    ref="uploadInput"
                    type="file"
                    multiple
                    :accept="
                        attachmentTypes.map((type) => `.${type}`).join(',')
                    "
                    class="block max-w-full"
                    @change="upload"
            /></label>
            <p class="text-sm">
                Up to {{ attachmentsMax }} private files,
                {{ attachmentMaxKb / 1024 }} MiB each. Avoid passwords and
                license or payment secrets.
            </p>
            <InputError
                v-for="(error, key) in form.errors"
                :key="key"
                :message="error"
            /><button
                :disabled="form.processing || (!internal && !ticket.can_reply)"
                class="rounded border p-3"
            >
                {{
                    form.processing
                        ? 'Sending…'
                        : internal
                          ? 'Add Internal Note'
                          : 'Send Reply'
                }}
            </button>
        </form>
        <section class="space-y-4">
            <label v-if="ticket.can_manage || ticket.can_escalate" class="block"
                >Action reason<textarea
                    id="support-action-reason"
                    v-model="actionForm.reason"
                    maxlength="2000"
                    class="block w-full rounded border p-3"
                />
            </label>
            <div v-if="ticket.can_manage" class="flex flex-wrap gap-3">
                <label
                    >Priority<select
                        v-model="actionForm.priority"
                        class="rounded border p-3"
                    >
                        <option v-for="p in ['low', 'normal', 'high']" :key="p">
                            {{ p }}
                        </option>
                    </select></label
                ><button
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('priority')"
                >
                    Change Priority</button
                ><button
                    v-if="ticket.is_escalated"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('deescalate')"
                >
                    Clear Escalation
                </button>
            </div>
            <InputError
                v-for="(error, key) in actionForm.errors"
                :key="key"
                :message="error"
            />
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="ticket.can_resolve"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('resolve')"
                >
                    Mark Resolved</button
                ><button
                    v-if="ticket.can_close"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('close')"
                >
                    Close Ticket</button
                ><button
                    v-if="ticket.can_reopen"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('reopen')"
                >
                    Reopen Ticket</button
                ><button
                    v-if="ticket.can_escalate"
                    :disabled="actionForm.processing"
                    class="rounded border p-3"
                    @click="action('escalate')"
                >
                    Request Escalation
                </button>
            </div>
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Support history</h2>
            <p v-for="(e, n) in ticket.events.data" :key="n">
                {{ e.type.replaceAll('_', ' ') }} ·
                {{ new Date(e.occurred_at).toLocaleString()
                }}<span v-if="e.reason" class="block break-words">{{
                    e.reason
                }}</span>
            </p>
            <nav class="flex gap-4">
                <Link
                    v-if="ticket.events.prev_page_url"
                    :href="ticket.events.prev_page_url"
                    preserve-scroll
                    >Previous history</Link
                ><Link
                    v-if="ticket.events.next_page_url"
                    :href="ticket.events.next_page_url"
                    preserve-scroll
                    >More history</Link
                >
            </nav>
        </section>
    </main>
</template>
