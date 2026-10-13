<script setup lang="ts">
import type { AuthorContent } from '@/types/authors';
import { countryName } from '@/types/authors';
defineProps<{ content: AuthorContent }>();
function safeUrl(value: string | null) {
    try {
        const url = new URL(value ?? '');
        return ['https:', 'http:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}
</script>
<template>
    <div class="space-y-5 rounded-2xl border bg-card p-6 sm:p-8">
        <section v-if="content.professional" class="space-y-4 border-b pb-5">
            <p
                class="text-sm font-medium tracking-wide text-muted-foreground uppercase"
            >
                Frozen professional evidence
            </p>
            <h2 class="text-2xl font-semibold break-words">
                {{ content.professional.professional_headline }}
            </h2>
            <p>
                {{ content.professional.years_of_experience ?? 'Not supplied' }}
                years professional experience
            </p>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="skill in [
                        ...content.professional.expertise,
                        ...content.professional.technologies,
                    ]"
                    :key="skill.name"
                    class="rounded-lg bg-muted px-3 py-1 text-sm"
                    >{{ skill.name }}</span
                >
            </div>
            <p
                v-if="content.professional.confidential_work"
                class="rounded-lg border p-4 text-sm break-words whitespace-pre-wrap"
            >
                Confidential work explanation:
                {{ content.professional.confidential_work }}
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="item in content.professional.portfolio"
                    :key="item.public_uuid"
                    class="min-w-0 overflow-hidden rounded-xl border"
                >
                    <img
                        v-if="item.image"
                        :src="item.image.url"
                        :alt="item.title + ' preview'"
                        loading="lazy"
                        class="aspect-video w-full object-cover"
                    />
                    <div class="space-y-2 p-4">
                        <h3 class="font-semibold break-words">
                            {{ item.title }}
                        </h3>
                        <p class="text-sm break-words">
                            {{ item.short_description }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.project_type }} ·
                            {{
                                item.is_public
                                    ? 'Public'
                                    : 'Not publicly listed'
                            }}
                        </p>
                        <a
                            v-if="safeUrl(item.project_url)"
                            :href="safeUrl(item.project_url) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm underline"
                            >External project ↗</a
                        >
                    </div>
                </article>
            </div>
        </section>
        <section>
            <h2 class="text-xl font-semibold">
                {{ content.display_name || 'Author Profile' }}
            </h2>
            <p class="mt-2 capitalize">
                {{ content.author_type }}
                <span v-if="content.company_name"
                    >· {{ content.company_name }}</span
                >
            </p>
            <p v-if="content.country_code">
                {{ countryName(content.country_code) }}
            </p>
        </section>
        <section>
            <h3 class="font-semibold">About</h3>
            <p class="mt-2 break-words whitespace-pre-wrap">
                {{ content.bio || 'Not provided' }}
            </p>
        </section>
        <section>
            <h3 class="font-semibold">Online presence</h3>
            <ul class="mt-2 space-y-2">
                <li
                    v-for="(url, label) in {
                        Website: content.website_url,
                        Portfolio: content.portfolio_url,
                        GitHub: content.github_url,
                        LinkedIn: content.linkedin_url,
                    }"
                    :key="label"
                >
                    <a
                        v-if="safeUrl(url)"
                        :href="safeUrl(url)!"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="break-all underline"
                        >{{ label }}: {{ url }}</a
                    >
                </li>
            </ul>
        </section>
        <section>
            <h3 class="font-semibold">Support contact</h3>
            <p class="break-all">
                {{ content.support_email || 'Not provided' }}
            </p>
            <p>{{ content.support_phone }}</p>
        </section>
    </div>
</template>
