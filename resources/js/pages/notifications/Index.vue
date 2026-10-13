<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { index, read, readAll, preferences } from '@/routes/notifications';
import type { NotificationItem } from '@/types/notifications';
import type { SupportPage } from '@/types/support';
defineProps<{
    notifications: SupportPage<NotificationItem> & {
        links: { url: string | null; label: string; active: boolean }[];
    };
    filter: string;
}>();
const page = usePage();
const busy = ref(false);
const labels: Record<string, string> = {
    orders: 'Purchases',
    refunds: 'Refunds',
    author_workflow: 'Author & product decisions',
    product_updates: 'Product updates',
    earnings: 'Earnings',
    payouts: 'Payouts',
    reviews: 'Reviews',
    support: 'Support',
};
function mark(id?: string) {
    busy.value = true;
    router.post(
        id ? read.url(id) : readAll.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
function date(value: string) {
    return new Date(value).toLocaleString();
}
</script>
<template>
    <Head title="Notifications" />
    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-3xl font-semibold">Notifications</h1>
                <p class="text-muted-foreground">
                    Updates about your purchases and marketplace activity.
                </p>
            </div>
            <Link :href="preferences()" class="text-sm underline"
                >Notification preferences</Link
            >
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <Link
                :href="index({ query: { filter: 'all' } })"
                class="rounded-md border px-3 py-2"
                :aria-current="filter === 'all' ? 'page' : undefined"
                >All</Link
            >
            <Link
                :href="index({ query: { filter: 'unread' } })"
                class="rounded-md border px-3 py-2"
                :aria-current="filter === 'unread' ? 'page' : undefined"
                >Unread ({{ page.props.notificationUnreadCount ?? 0 }})</Link
            >
            <Button
                variant="outline"
                :disabled="busy || !page.props.notificationUnreadCount"
                @click="mark()"
                >Mark all as read</Button
            >
        </div>
        <div
            v-if="!notifications.data.length"
            class="rounded-xl border p-10 text-center"
        >
            <Bell class="mx-auto mb-3 size-8 text-muted-foreground" />
            <h2 class="font-semibold">
                {{
                    filter === 'unread'
                        ? 'You’re all caught up'
                        : 'No notifications yet'
                }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Important updates will appear here.
            </p>
        </div>
        <article
            v-for="item in notifications.data"
            :key="item.id"
            class="space-y-3 rounded-xl border p-5"
            :class="!item.read_at ? 'border-primary/40 bg-primary/5' : ''"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 class="font-semibold">
                    {{ item.title }}
                    <span v-if="!item.read_at" class="ml-2 text-xs text-primary"
                        >Unread</span
                    >
                </h2>
                <time
                    :datetime="item.occurred_at"
                    class="text-xs text-muted-foreground"
                    >{{ date(item.occurred_at) }}</time
                >
            </div>
            <p class="text-xs text-muted-foreground">
                {{ labels[item.category] ?? 'Account' }}
            </p>
            <p class="text-sm">{{ item.message }}</p>
            <div class="flex flex-wrap items-center gap-4">
                <Link
                    v-if="item.action_path"
                    :href="item.action_path"
                    class="text-sm font-medium underline"
                    >{{ item.action_label }}</Link
                ><Button
                    v-if="!item.read_at"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="mark(item.id)"
                    >Mark as read</Button
                >
            </div>
        </article>
        <nav aria-label="Notification pages" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in notifications.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border px-3 py-2 text-sm"
                    :aria-current="link.active ? 'page' : undefined"
                    >{{ link.label.replace(/&laquo;|&raquo;/g, '') }}</Link
                ></template
            >
        </nav>
    </main>
</template>
