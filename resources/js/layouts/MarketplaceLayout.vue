<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Menu, Search, ShoppingCart, ArrowUpRight } from '@lucide/vue';
import MarketplaceAccountMenu from '@/components/marketplace/MarketplaceAccountMenu.vue';
import SoftwareDiscoveryMenu from '@/components/marketplace/SoftwareDiscoveryMenu.vue';
import AppLogo from '@/components/AppLogo.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetDescription,
    SheetTrigger,
} from '@/components/ui/sheet';
import { dashboard, home, login, register } from '@/routes';
import { index as cartIndex } from '@/routes/commerce/cart';
import { index as ordersIndex } from '@/routes/commerce/orders';
import { index as libraryIndex } from '@/routes/library';
import { index } from '@/routes/marketplace/products';
const page = usePage();
const open = ref(false),
    search = ref('');
const identity = computed(
    () =>
        page.props.marketplaceIdentity as {
            marketplace_name: string;
            marketplace_tagline: string;
        },
);
function discover() {
    open.value = false;
    router.get(index.url(), { search: search.value });
}
</script>
<template>
    <div class="min-h-screen bg-background text-foreground">
        <a href="#main-content" class="skip-link">Skip to content</a>
        <header class="public-header glass-nav sticky top-0 z-30">
            <div
                class="public-shell flex min-h-18 items-center justify-between gap-2 py-4 sm:gap-4"
            >
                <Link
                    :href="home()"
                    aria-label="Templaara home"
                    class="shrink-0"
                    ><AppLogo class="!h-6 sm:!h-8"
                /></Link>
                <nav
                    aria-label="Main navigation"
                    class="hidden items-center gap-3 text-sm font-medium lg:flex"
                >
                    <Link :href="home()" class="hover:text-primary">Home</Link
                    ><SoftwareDiscoveryMenu /><Link
                        :href="home().url + '#industries'"
                        class="hover:text-primary"
                        >Industries</Link
                    >
                </nav>
                <form
                    class="hidden max-w-[360px] min-w-0 grow items-center gap-2 xl:flex"
                    role="search"
                    @submit.prevent="discover"
                >
                    <div class="relative grow">
                        <Search
                            aria-hidden="true"
                            class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                        /><Input
                            v-model="search"
                            type="search"
                            maxlength="100"
                            aria-label="Search business software"
                            placeholder="Search business software…"
                            class="bg-muted/40 pl-9"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        aria-label="Search"
                        ><Search class="size-4"
                    /></Button>
                </form>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <NotificationBell />
                    <Link
                        :href="cartIndex()"
                        aria-label="Your cart"
                        class="flex min-h-11 items-center gap-1.5 rounded-md px-2 hover:bg-muted"
                        ><ShoppingCart aria-hidden="true" class="size-5" /><span
                            class="text-xs font-semibold"
                            >{{ page.props.cartCount ?? 0 }}</span
                        ></Link
                    >
                    <MarketplaceAccountMenu v-if="page.props.auth.user" />
                    <Link
                        v-else
                        :href="login()"
                        class="hidden rounded-md px-3 py-2 text-sm font-medium sm:block"
                        >Sign in</Link
                    >
                    <Button
                        v-if="!page.props.auth.user"
                        as-child
                        class="hidden sm:inline-flex"
                        ><Link :href="register()">Get started</Link></Button
                    >
                    <Sheet v-model:open="open"
                        ><SheetTrigger as-child
                            ><Button
                                variant="outline"
                                size="icon"
                                aria-label="Open navigation"
                                class="lg:hidden"
                                ><Menu class="size-5" /></Button
                        ></SheetTrigger>
                        <SheetContent class="overflow-y-auto px-5 py-8"
                            ><SheetTitle>Explore Templaara</SheetTitle
                            ><SheetDescription
                                >Discover software and manage your
                                workspace.</SheetDescription
                            >
                            <form
                                role="search"
                                class="mt-6 flex gap-2"
                                @submit.prevent="discover"
                            >
                                <Input
                                    v-model="search"
                                    type="search"
                                    maxlength="100"
                                    aria-label="Search business software"
                                    placeholder="Search software…"
                                /><Button type="submit" aria-label="Search"
                                    ><Search class="size-4"
                                /></Button>
                            </form>
                            <nav
                                aria-label="Mobile navigation"
                                class="mt-6 grid gap-1"
                                @click="open = false"
                            >
                                <Link
                                    :href="home()"
                                    class="rounded-md p-3 hover:bg-muted"
                                    >Home</Link
                                ><Link
                                    :href="index()"
                                    class="rounded-md p-3 hover:bg-muted"
                                    >Browse Software</Link
                                ><Link
                                    :href="home().url + '#industries'"
                                    class="rounded-md p-3 hover:bg-muted"
                                    >Industries</Link
                                >
                                <template v-if="page.props.auth.user"
                                    ><Link
                                        :href="dashboard()"
                                        class="rounded-md p-3 hover:bg-muted"
                                        >Workspace</Link
                                    ><Link
                                        :href="ordersIndex()"
                                        class="rounded-md p-3 hover:bg-muted"
                                        >My Orders</Link
                                    ><Link
                                        :href="libraryIndex()"
                                        class="rounded-md p-3 hover:bg-muted"
                                        >My Library</Link
                                    ></template
                                >
                                <template v-else
                                    ><Link
                                        :href="login()"
                                        class="rounded-md p-3 hover:bg-muted"
                                        >Sign in</Link
                                    ><Link
                                        :href="register()"
                                        class="rounded-md p-3 hover:bg-muted"
                                        >Create an account</Link
                                    ></template
                                >
                                <Link
                                    :href="
                                        page.props.authorNavigation?.url ??
                                        '/author/onboarding'
                                    "
                                    class="rounded-md p-3 text-primary hover:bg-muted"
                                    >{{
                                        page.props.authorNavigation?.label ??
                                        'Become an author'
                                    }}</Link
                                >
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>
        <main id="main-content" class="public-main">
            <div class="public-shell"><slot /></div>
        </main>
        <footer class="mt-12 border-t bg-card">
            <div
                class="public-shell grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr]"
            >
                <div>
                    <AppLogo />
                    <p class="mt-4 max-w-sm text-sm text-muted-foreground">
                        {{
                            identity.marketplace_tagline ||
                            'Ready-to-use software for the work your business does.'
                        }}
                    </p>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Business value first. Clear packages. Real product
                        information.
                    </p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">Discover</h2>
                    <div class="mt-4 grid gap-3 text-sm text-muted-foreground">
                        <Link :href="index()">Browse Software</Link
                        ><Link :href="home().url + '#industries'"
                            >Explore categories</Link
                        ><Link :href="home().url + '#industries'"
                            >Software for your industry</Link
                        >
                    </div>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">Your workspace</h2>
                    <div class="mt-4 grid gap-3 text-sm text-muted-foreground">
                        <Link :href="libraryIndex()">Software library</Link
                        ><Link :href="ordersIndex()">Orders &amp; invoices</Link
                        ><Link
                            :href="
                                page.props.authorNavigation?.url ??
                                '/author/onboarding'
                            "
                            class="inline-flex items-center gap-1"
                            >{{
                                page.props.authorNavigation?.label ??
                                'Become an author'
                            }}<ArrowUpRight aria-hidden="true" class="size-3"
                        /></Link>
                    </div>
                </div>
            </div>
            <div
                class="public-shell border-t py-5 text-xs text-muted-foreground"
            >
                Templaara · Software built for real businesses.
            </div>
        </footer>
    </div>
</template>
