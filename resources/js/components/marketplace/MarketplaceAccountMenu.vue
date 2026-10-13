<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    LayoutDashboard,
    Library,
    ShoppingBag,
    LifeBuoy,
    Settings,
    LogOut,
    Store,
    ShieldCheck,
} from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
    DropdownMenuLabel,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useInitials } from '@/composables/useInitials';
import { computed } from 'vue';
import { logout } from '@/routes';
const page = usePage();
const { getInitials } = useInitials();
const account = computed(
    () =>
        page.props.marketplaceAccount as {
            email: string;
            avatar: string | null;
            author_url: string | null;
            admin_url: string | null;
        } | null,
);
const identity = computed(() => ({
    ...page.props.auth.user,
    email: account.value?.email ?? '',
    avatar: account.value?.avatar ?? undefined,
}));
const links = [
    { label: 'Dashboard', url: '/dashboard', icon: LayoutDashboard },
    { label: 'My Library', url: '/account/library', icon: Library },
    { label: 'Orders', url: '/account/orders', icon: ShoppingBag },
    { label: 'Invoices', url: '/account/invoices', icon: ShoppingBag },
    { label: 'My Support', url: '/account/support', icon: LifeBuoy },
    { label: 'Account Settings', url: '/settings/profile', icon: Settings },
];
</script>
<template>
    <DropdownMenu v-if="page.props.auth.user">
        <DropdownMenuTrigger as-child
            ><button
                type="button"
                aria-label="Open account menu"
                class="flex min-h-11 items-center gap-2 rounded-full border bg-white p-1.5 shadow-sm focus-visible:ring-2 focus-visible:ring-ring"
            >
                <Avatar class="size-8"
                    ><AvatarImage
                        v-if="identity.avatar"
                        :src="identity.avatar"
                        :alt="page.props.auth.user.name"
                    /><AvatarFallback class="bg-brand-blue-soft text-primary">{{
                        getInitials(page.props.auth.user.name)
                    }}</AvatarFallback></Avatar
                ><ChevronDown
                    class="mr-1 hidden size-3.5 sm:block"
                    aria-hidden="true"
                /></button
        ></DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="w-72 max-w-[calc(100vw-24px)] rounded-2xl p-2"
        >
            <DropdownMenuLabel class="p-2"
                ><UserInfo :user="identity" show-email /></DropdownMenuLabel
            ><DropdownMenuSeparator />
            <DropdownMenuItem v-for="item in links" :key="item.url" as-child
                ><Link :href="item.url" class="gap-3 rounded-lg py-2.5"
                    ><component
                        :is="item.icon"
                        class="size-4"
                        aria-hidden="true"
                    />{{ item.label }}</Link
                ></DropdownMenuItem
            >
            <DropdownMenuItem v-if="account?.author_url" as-child
                ><Link :href="account!.author_url!" class="gap-3"
                    ><Store class="size-4" />Author Workspace</Link
                ></DropdownMenuItem
            >
            <DropdownMenuItem v-if="account?.admin_url" as-child
                ><Link :href="account!.admin_url!" class="gap-3"
                    ><ShieldCheck class="size-4" />Admin Workspace</Link
                ></DropdownMenuItem
            >
            <DropdownMenuSeparator /><DropdownMenuItem as-child
                ><Link
                    :href="logout()"
                    as="button"
                    class="w-full gap-3"
                    @click="router.flushAll()"
                    ><LogOut class="size-4" />Log out</Link
                ></DropdownMenuItem
            >
        </DropdownMenuContent>
    </DropdownMenu>
</template>
