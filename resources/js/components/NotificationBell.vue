<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import { index } from '@/routes/notifications';
const page = usePage();
const count = computed(() => Number(page.props.notificationUnreadCount ?? 0));
</script>
<template>
    <Link
        v-if="page.props.auth.user"
        :href="index()"
        class="relative inline-flex h-10 min-w-10 items-center justify-center rounded-md border px-2 hover:bg-muted"
        :aria-label="`Notifications, ${count} unread`"
    >
        <Bell class="size-5" />
        <span
            v-if="count > 0"
            class="ml-1 rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground"
            >{{ count > 99 ? '99+' : count }}</span
        >
    </Link>
</template>
