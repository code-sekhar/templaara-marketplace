<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Layers, Monitor, BriefcaseBusiness } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';
defineProps<{ title?: string; description?: string }>();
</script>
<template>
    <div class="grid min-h-svh bg-background lg:grid-cols-2">
        <aside
            class="auth-atmosphere hidden flex-col justify-between border-r p-12 lg:flex"
        >
            <Link :href="home()" aria-label="Templaara home"><AppLogo /></Link>
            <div class="max-w-lg">
                <p class="eyebrow">Your business, better equipped</p>
                <h2
                    class="mt-5 text-4xl leading-tight font-semibold tracking-tight"
                >
                    Find software that fits the way you work.
                </h2>
                <p class="mt-5 text-lg text-muted-foreground">
                    Discover business solutions, compare clear packages and
                    manage your purchased software in one workspace.
                </p>
                <div class="mt-8 grid gap-4">
                    <p class="flex items-center gap-3">
                        <BriefcaseBusiness
                            class="size-5 text-primary"
                        />Business-focused discovery
                    </p>
                    <p class="flex items-center gap-3">
                        <Monitor class="size-5 text-primary" />Product
                        information and live demos
                    </p>
                    <p class="flex items-center gap-3">
                        <Layers class="size-5 text-primary" />A workspace for
                        your software
                    </p>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">
                Software built for real businesses.
            </p>
        </aside>
        <main class="flex items-center justify-center px-6 py-12 sm:px-10">
            <div class="elevated-card w-full max-w-md p-6 sm:p-8">
                <Link
                    :href="home()"
                    aria-label="Templaara home"
                    class="mb-8 block lg:hidden"
                    ><AppLogo
                /></Link>
                <header class="mb-8">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p class="mt-3 text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </header>
                <slot />
            </div>
        </main>
    </div>
</template>
