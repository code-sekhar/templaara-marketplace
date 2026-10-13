<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
} from '@/components/ui/dropdown-menu';
import NotificationBell from '@/components/NotificationBell.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
const page = usePage();
const { getInitials } = useInitials();
const context = computed(() =>
    page.url.startsWith('/admin')
        ? 'Admin workspace'
        : page.url.startsWith('/author')
          ? 'Author workspace'
          : 'Your workspace',
);
const customerTitles: Record<string, string> = {
    '/dashboard': 'Dashboard',
    '/account/orders': 'My Orders',
    '/account/library': 'My Library',
    '/account/invoices': 'Invoices & credit notes',
    '/account/support': 'My Support',
    '/account/reviews': 'My Reviews',
    '/account/refunds': 'My Refunds',
};
const displayedBreadcrumbs = computed(() => {
    const path = page.url.split('?')[0].replace(/\/$/, '');
    const title = customerTitles[path];
    if (title && (!props.breadcrumbs.length || path === '/dashboard')) {
        return [
            { title: 'Workspace', href: '/dashboard' },
            { title, href: path },
        ];
    }
    return props.breadcrumbs;
});
</script>

<template>
    <header
        class="workspace-topbar glass-nav flex min-h-14 shrink-0 items-center gap-3 border-b border-sidebar-border px-4 py-2 md:px-6"
    >
        <div class="flex min-w-0 items-center gap-3">
            <SidebarTrigger class="-ml-1" />
            <span
                v-if="
                    !customerTitles[page.url.split('?')[0].replace(/\/$/, '')]
                "
                class="hidden shrink-0 border-r pr-3 text-xs font-medium text-muted-foreground lg:block"
                >{{ context }}</span
            >
            <template v-if="displayedBreadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="displayedBreadcrumbs" />
            </template>
        </div>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <Link
                href="/products"
                class="hidden rounded-lg px-3 py-2 text-xs font-medium text-primary hover:bg-muted sm:block"
                >Browse software</Link
            ><NotificationBell /><DropdownMenu v-if="page.props.auth.user"
                ><DropdownMenuTrigger
                    aria-label="Account menu"
                    class="flex size-9 items-center justify-center rounded-full border bg-white hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    ><Avatar class="size-7 rounded-full"
                        ><AvatarImage
                            v-if="page.props.auth.user.avatar"
                            :src="page.props.auth.user.avatar"
                            :alt="page.props.auth.user.name"
                        /><AvatarFallback
                            class="rounded-full bg-brand-blue-soft text-xs text-primary"
                            >{{
                                getInitials(page.props.auth.user.name)
                            }}</AvatarFallback
                        ></Avatar
                    ></DropdownMenuTrigger
                ><DropdownMenuContent align="end" class="min-w-56"
                    ><UserMenuContent
                        :user="page.props.auth.user" /></DropdownMenuContent
            ></DropdownMenu>
        </div>
    </header>
</template>
