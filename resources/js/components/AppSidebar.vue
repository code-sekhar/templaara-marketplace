<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    LayoutGrid,
    Library,
    ShoppingBag,
    LifeBuoy,
    Wallet,
    Users,
    ChartColumn,
    Settings,
    Star,
    Package,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuthorization } from '@/composables/useAuthorization';
import * as productUpdateRoutes from '@/routes/admin/product-updates';
import * as productReviewRoutes from '@/routes/admin/product-reviews';
import { productRoutes } from '@/lib/products';
import { catalogLabels, catalogRoutes } from '@/lib/catalog';
import type { CatalogKind } from '@/types/catalog';
import * as customerOrders from '@/routes/commerce/orders';
import * as adminOrders from '@/routes/admin/orders';
import { index as libraryIndex } from '@/routes/library';
import { index as entitlementIndex } from '@/routes/admin/entitlements';
import { dashboard } from '@/routes';
import { usePage } from '@inertiajs/vue3';
import * as authorProfile from '@/routes/author/profile';
import * as authorDirectory from '@/routes/admin/authors';
import * as authorApplications from '@/routes/admin/author-applications';
import * as adminRefunds from '@/routes/admin/refunds';
import * as customerRefunds from '@/routes/account/refunds';
import * as customerReviews from '@/routes/account/reviews';
import * as authorReviews from '@/routes/author/reviews';
import * as adminReviews from '@/routes/admin/reviews';
import * as customerSupport from '@/routes/account/support';
import * as authorSupport from '@/routes/author/support';
import * as adminSupport from '@/routes/admin/support';
import * as marketplaceSettings from '@/routes/admin/marketplace-settings';
import * as analyticsRoutes from '@/routes/admin/analytics';
import type { NavItem } from '@/types';

import * as authorEarnings from '@/routes/author/earnings';
import * as adminEarnings from '@/routes/admin/earnings';
import * as commissions from '@/routes/admin/commissions';
import * as authorPayouts from '@/routes/author/payouts';
import * as payoutMethods from '@/routes/author/payout-methods';
import * as adminPayouts from '@/routes/admin/payouts';
const page = usePage();
const { can, hasRole } = useAuthorization();
const mainNavItems = computed<NavItem[]>(() => [
    { title: 'My Invoices', href: '/account/invoices', icon: LayoutGrid },
    ...(can('orders.view')
        ? [{ title: 'Invoices', href: '/admin/invoices', icon: LayoutGrid }]
        : []),
    ...(can('settings.view')
        ? [
              {
                  title: 'Marketplace Settings',
                  href: marketplaceSettings.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can('reports.view')
        ? [
              {
                  title: 'Marketplace Analytics',
                  href: analyticsRoutes.dashboard(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Reports',
                  href: analyticsRoutes.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    { title: 'My Support', href: customerSupport.index(), icon: LayoutGrid },
    ...(hasRole('author')
        ? [{ title: 'Support', href: authorSupport.index(), icon: LayoutGrid }]
        : []),
    ...(can('support.view')
        ? [
              {
                  title: 'Support Tickets',
                  href: adminSupport.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    { title: 'My Reviews', href: customerReviews.index(), icon: LayoutGrid },
    ...(hasRole('author')
        ? [
              {
                  title: 'Customer Reviews',
                  href: authorReviews.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can('reviews.view')
        ? [
              {
                  title: 'Review Moderation',
                  href: adminReviews.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    { title: 'My Refunds', href: customerRefunds.index(), icon: LayoutGrid },
    ...(can('refunds.view')
        ? [
              {
                  title: 'Refund Queue',
                  href: adminRefunds.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(hasRole('author')
        ? [
              {
                  title: 'My Payouts',
                  href: authorPayouts.index(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Payout Methods',
                  href: payoutMethods.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can('payouts.view')
        ? [
              {
                  title: 'Payout Queue',
                  href: adminPayouts.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(hasRole('author')
        ? [
              {
                  title: 'My Earnings',
                  href: authorEarnings.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can('commissions.view')
        ? [
              {
                  title: 'Author Earnings',
                  href: adminEarnings.index(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Commission Policies',
                  href: commissions.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(page.props.authorNavigation
        ? [
              {
                  title: page.props.authorNavigation.label,
                  href: page.props.authorNavigation.url,
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(hasRole('author')
        ? [
              {
                  title: 'Author Profile',
                  href: authorProfile.show(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can('authors.view')
        ? [
              {
                  title: 'Author Directory',
                  href: authorDirectory.index(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Author Applications',
                  href: authorApplications.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    { title: 'My Library', href: libraryIndex(), icon: LayoutGrid },
    ...(can('orders.view')
        ? [
              {
                  title: 'Purchase Entitlements',
                  href: entitlementIndex(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    { title: 'My Orders', href: customerOrders.index(), icon: LayoutGrid },
    ...(can('orders.view')
        ? [{ title: 'All Orders', href: adminOrders.index(), icon: LayoutGrid }]
        : []),
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
]);
const catalogNavItems = computed<NavItem[]>(() =>
    (['categories', 'industries', 'technologies'] as CatalogKind[])
        .filter((kind) => can(`${kind}.view`))
        .map((kind) => ({
            title: catalogLabels[kind].plural,
            href: catalogRoutes[kind].index(),
            icon: LayoutGrid,
        })),
);
const productNavItems = computed<NavItem[]>(() => [
    ...(can('products.review')
        ? [
              {
                  title: 'Product Reviews',
                  href: productReviewRoutes.index(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Product Update Reviews',
                  href: productUpdateRoutes.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(hasRole('admin') && can('products.view')
        ? [
              {
                  title: 'All Products',
                  href: productRoutes.admin.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(hasRole('author') && can('products.view')
        ? [
              {
                  title: 'My Products',
                  href: productRoutes.author.index(),
                  icon: LayoutGrid,
              },
          ]
        : []),
]);

const navGroups = computed(() => {
    const items = [
        ...mainNavItems.value,
        ...catalogNavItems.value,
        ...productNavItems.value,
    ];
    const groups: Record<string, NavItem[]> = {
        Overview: [],
        'Your account': [],
        'Author workspace': [],
        Catalog: [],
        Marketplace: [],
        Finance: [],
        Insights: [],
        System: [],
    };
    for (const item of items) {
        const personal =
            item.title.startsWith('My ') || item.title === 'Payout Methods';
        const group =
            item.title === 'Dashboard'
                ? 'Overview'
                : item.title === 'Marketplace Settings'
                  ? 'System'
                  : ['Marketplace Analytics', 'Reports'].includes(item.title)
                    ? 'Insights'
                    : [
                            'Author Earnings',
                            'Commission Policies',
                            'Payout Queue',
                            'Invoices',
                        ].includes(item.title)
                      ? 'Finance'
                      : [
                              'All Products',
                              'Product Reviews',
                              'Product Update Reviews',
                              'Categories',
                              'Industries',
                              'Technologies',
                          ].includes(item.title)
                        ? 'Catalog'
                        : [
                                'Author Dashboard',
                                'Author Profile',
                                'Author Application',
                                'Sell on Templaara',
                                'My Products',
                                'My Earnings',
                                'My Payouts',
                                'Payout Methods',
                                'Customer Reviews',
                                'Support',
                            ].includes(item.title)
                          ? 'Author workspace'
                          : personal
                            ? 'Your account'
                            : 'Marketplace';
        const icon = /Support/.test(item.title)
            ? LifeBuoy
            : /Earnings|Payout|Commission|Refund/.test(item.title)
              ? Wallet
              : /Authors|Author Directory|Author Applications|Profile/.test(
                      item.title,
                  )
                ? Users
                : /Analytics|Reports/.test(item.title)
                  ? ChartColumn
                  : /Settings/.test(item.title)
                    ? Settings
                    : /Orders|Invoices/.test(item.title)
                      ? ShoppingBag
                      : /Library|Entitlements/.test(item.title)
                        ? Library
                        : /Reviews/.test(item.title)
                          ? Star
                          : /Products|Categories|Industries|Technologies/.test(
                                  item.title,
                              )
                            ? Package
                            : LayoutGrid;
        groups[group].push({ ...item, icon });
    }
    return Object.entries(groups).filter(([, items]) => items.length);
});
const footerNavItems: NavItem[] = [
    { title: 'Browse Software', href: '/products', icon: ShoppingBag },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="[label, items] in navGroups"
                :key="label"
                :items="items"
                :label="label"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
