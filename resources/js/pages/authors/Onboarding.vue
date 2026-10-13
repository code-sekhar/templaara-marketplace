<script setup lang="ts">
import { watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ApplicationHistory from '@/components/authors/ApplicationHistory.vue';
import AuthorSnapshot from '@/components/authors/AuthorSnapshot.vue';
import PortfolioEditor from '@/components/authors/PortfolioEditor.vue';
import * as onboarding from '@/routes/author/onboarding';
import * as profileRoutes from '@/routes/author/profile';
import { dashboard } from '@/routes/author';
import { authorStatusLabels, countryName } from '@/types/authors';
import type { AuthorProfile, AuthorHistory } from '@/types/authors';
import type {
    PortfolioItem,
    ProfessionalEvidence,
    TaxonomyOption,
    IdentityImage,
} from '@/types/author-professional';
const props = defineProps<{
    profile:
        | (AuthorProfile &
              Partial<ProfessionalEvidence> & {
                  revision: number;
                  technology_ids: number[];
                  expertise_ids: number[];
              })
        | null;
    latestFeedback: { section: string; message: string; created_at: string }[];
    editable: boolean;
    legacy: boolean;
    countries: string[];
    history: AuthorHistory | null;
    portfolio: PortfolioItem[];
    media: (NonNullable<IdentityImage> & { purpose: string })[];
    completeness: { percentage: number; missing: string[] };
    professional_options: {
        technologies: TaxonomyOption[];
        expertise: TaxonomyOption[];
        languages: string[];
        team_sizes: string[];
    };
}>();
const page = usePage();
const p = props.profile;
const form = useForm({
    display_name: p?.display_name ?? '',
    company_name: p?.company_name ?? '',
    slug: p?.slug ?? '',
    author_type: p?.author_type ?? '',
    country_code: p?.country_code ?? '',
    bio: p?.bio ?? '',
    website_url: p?.website_url ?? '',
    portfolio_url: p?.portfolio_url ?? '',
    github_url: p?.github_url ?? '',
    linkedin_url: p?.linkedin_url ?? '',
    support_email: p?.support_email ?? '',
    support_phone: p?.support_phone ?? '',
    professional_headline: p?.professional_headline ?? '',
    years_of_experience: p?.years_of_experience ?? ('' as number | string),
    founded_year: p?.founded_year ?? ('' as number | string),
    team_size: p?.team_size ?? '',
    state_region: p?.state_region ?? '',
    city: p?.city ?? '',
    public_location: p?.public_location ?? false,
    languages: p?.languages ?? [],
    support_availability: p?.support_availability ?? '',
    confidential_work: p?.confidential_work ?? '',
    technology_ids: p?.technology_ids ?? [],
    expertise_ids: p?.expertise_ids ?? [],
    revision: p?.revision ?? 0,
});
const submission = useForm({});
const upload = useForm({
    file: null as File | null,
    purpose: 'avatar',
    revision: p?.revision ?? 0,
});
function save() {
    form.put(
        page.url.startsWith('/author/profile')
            ? profileRoutes.save.url()
            : onboarding.save.url(),
        {
            preserveScroll: true,
            onSuccess: () => {
                form.revision = props.profile?.revision ?? 0;
                form.defaults();
            },
        },
    );
}
function image(event: Event, purpose: string) {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.purpose = purpose;
    upload.revision = props.profile?.revision ?? 0;
    if (upload.file)
        upload.post(profileRoutes.media.url(), { preserveScroll: true });
}
watch(
    () => props.profile?.revision,
    (revision) => {
        if (!form.isDirty) {
            form.revision = revision ?? 0;
            form.defaults();
        }
    },
);
const identityLocked = props.profile?.status === 'approved';
</script>
<template>
    <Head
        :title="
            profile?.status === 'approved'
                ? 'Professional profile'
                : 'Become a Templaara author'
        "
    />
    <div role="main" class="mx-auto w-full max-w-6xl space-y-7 p-4 sm:p-7">
        <header class="flex flex-wrap items-start justify-between gap-5">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Your author workspace
                </p>
                <h1
                    class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    {{
                        profile?.status === 'approved'
                            ? 'Your professional profile'
                            : 'Build your author identity'
                    }}
                </h1>
                <p class="mt-3 leading-relaxed text-muted-foreground">
                    Help businesses understand who you are, what you build and
                    the experience behind your software.
                </p>
            </div>
            <Link
                v-if="identityLocked"
                :href="dashboard()"
                class="rounded-lg border px-4 py-2 text-sm font-medium"
                >Author dashboard</Link
            >
        </header>
        <div class="grid items-start gap-7 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="space-y-5 lg:sticky lg:top-6">
                <div class="rounded-2xl border bg-card p-5">
                    <p
                        class="text-xs font-medium text-muted-foreground uppercase"
                    >
                        Marketplace application
                    </p>
                    <p class="mt-2 font-semibold">
                        {{
                            legacy
                                ? 'Existing author'
                                : authorStatusLabels[profile?.status ?? 'draft']
                        }}
                    </p>
                    <p class="mt-3 text-sm text-muted-foreground">
                        Approval confirms our application process, not
                        government certification or a product guarantee.
                    </p>
                </div>
                <div class="rounded-2xl border p-5">
                    <div class="flex justify-between text-sm">
                        <span>Profile completeness</span
                        ><strong>{{ completeness.percentage }}%</strong>
                    </div>
                    <progress
                        :value="completeness.percentage"
                        max="100"
                        class="mt-3 h-2 w-full"
                        aria-label="Profile completeness"
                    />
                    <p
                        class="mt-3 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{
                            completeness.missing.length
                                ? 'Recommended: ' +
                                  completeness.missing.join(', ')
                                : 'Your professional sections are complete.'
                        }}
                        Completeness does not grant verification.
                    </p>
                </div>
                <nav aria-label="Profile sections" class="grid gap-2 text-sm">
                    <a
                        v-for="(title, index) in [
                            'Identity',
                            'Professional background',
                            'Expertise',
                            'Links & communication',
                            'Professional work',
                        ]"
                        :key="title"
                        :href="
                            '#' +
                            [
                                'identity',
                                'background',
                                'expertise',
                                'links',
                                'portfolio',
                            ][index]
                        "
                        class="rounded-lg px-3 py-2 hover:bg-muted"
                        >{{ index + 1 }}. {{ title }}</a
                    >
                </nav>
                <Link
                    v-if="identityLocked && profile"
                    :href="'/authors/' + profile.slug"
                    class="block rounded-lg border px-4 py-3 text-center text-sm font-medium"
                    >View public profile ↗</Link
                >
            </aside>
            <main class="min-w-0 space-y-6">
                <section
                    v-if="latestFeedback.length"
                    role="status"
                    class="rounded-2xl border border-amber-400 bg-amber-50 p-5 dark:bg-amber-950/20"
                >
                    <h2 class="font-semibold">Changes to address</h2>
                    <div
                        v-for="(item, index) in latestFeedback"
                        :key="index"
                        class="mt-4"
                    >
                        <p class="text-xs font-semibold uppercase">
                            {{ item.section }} ·
                            {{ new Date(item.created_at).toLocaleDateString() }}
                        </p>
                        <p class="mt-1 text-sm break-words whitespace-pre-wrap">
                            {{ item.message }}
                        </p>
                    </div>
                    <p class="mt-4 text-sm">
                        Save your corrections, then resubmit for a new review
                        round.
                    </p>
                </section>
                <div
                    v-if="!editable"
                    class="rounded-xl border bg-muted/30 p-5 text-sm"
                >
                    Your submitted professional profile is frozen while it is
                    reviewed.
                </div>
                <form v-if="editable" class="space-y-6" @submit.prevent="save">
                    <section
                        id="identity"
                        class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                    >
                        <div>
                            <h2 class="text-xl font-semibold">Identity</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Display name is public. Legal/company name and
                                support contacts remain private.
                                {{
                                    identityLocked
                                        ? 'Verified identity fields are locked; contact Templaara for a reviewed change.'
                                        : ''
                                }}
                            </p>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm font-medium"
                                >Author type<select
                                    id="author_type"
                                    :aria-invalid="!!form.errors.author_type"
                                    aria-describedby="error-author_type"
                                    v-model="form.author_type"
                                    :disabled="identityLocked"
                                    class="h-10 rounded-md border bg-background px-3"
                                >
                                    <option value="">Choose a type</option>
                                    <option value="individual">
                                        Individual
                                    </option>
                                    <option value="company">Company</option>
                                </select></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Public display name<Input
                                    id="display_name"
                                    :aria-invalid="!!form.errors.display_name"
                                    aria-describedby="error-display_name"
                                    v-model="form.display_name"
                                    :disabled="identityLocked"
                                    maxlength="150" /></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Legal/company name (private)<Input
                                    id="company_name"
                                    :aria-invalid="!!form.errors.company_name"
                                    aria-describedby="error-company_name"
                                    v-model="form.company_name"
                                    :disabled="identityLocked"
                                    maxlength="200" /></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Country<select
                                    id="country_code"
                                    :aria-invalid="!!form.errors.country_code"
                                    aria-describedby="error-country_code"
                                    v-model="form.country_code"
                                    :disabled="identityLocked"
                                    class="h-10 max-w-full rounded-md border bg-background px-3"
                                >
                                    <option value="">Choose a country</option>
                                    <option
                                        v-for="code in countries"
                                        :key="code"
                                        :value="code"
                                    >
                                        {{ countryName(code) }}
                                    </option>
                                </select></label
                            >
                        </div>
                        <label class="grid gap-2 text-sm font-medium"
                            >Public profile address<Input
                                id="slug"
                                :aria-invalid="!!form.errors.slug"
                                aria-describedby="error-slug"
                                v-model="form.slug"
                                :disabled="identityLocked"
                                placeholder="Generated from your display name"
                            />
                            <p
                                v-if="form.errors.display_name"
                                id="error-display_name"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.display_name }}
                            </p>
                            <p
                                v-if="form.errors.company_name"
                                id="error-company_name"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.company_name }}
                            </p>
                            <p
                                v-if="form.errors.slug"
                                id="error-slug"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.slug }}
                            </p>
                            <p
                                v-if="form.errors.author_type"
                                id="error-author_type"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.author_type }}
                            </p>
                            <p
                                v-if="form.errors.country_code"
                                id="error-country_code"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.country_code }}
                            </p></label
                        >
                    </section>
                    <section
                        id="background"
                        class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                    >
                        <h2 class="text-xl font-semibold">
                            Professional background
                        </h2>
                        <label class="grid gap-2 text-sm font-medium"
                            >Professional headline<Input
                                id="professional_headline"
                                :aria-invalid="
                                    !!form.errors.professional_headline
                                "
                                aria-describedby="error-professional_headline"
                                v-model="form.professional_headline"
                                maxlength="160"
                                placeholder="Business software specialist for retail and operations" /></label
                        ><label class="grid gap-2 text-sm font-medium"
                            >About your work<textarea
                                id="bio"
                                :aria-invalid="!!form.errors.bio"
                                aria-describedby="error-bio"
                                v-model="form.bio"
                                maxlength="5000"
                                rows="5"
                                class="rounded-md border bg-background p-3 font-normal"
                                placeholder="Describe your experience, the businesses you help and what you build."
                            />
                            <p
                                v-if="form.errors.professional_headline"
                                id="error-professional_headline"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.professional_headline }}
                            </p>
                            <p
                                v-if="form.errors.bio"
                                id="error-bio"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.bio }}
                            </p></label
                        >
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm font-medium"
                                >Years of professional experience<Input
                                    id="years_of_experience"
                                    :aria-invalid="
                                        !!form.errors.years_of_experience
                                    "
                                    aria-describedby="error-years_of_experience"
                                    v-model="form.years_of_experience"
                                    type="number"
                                    min="0"
                                    max="60" /></label
                            ><label
                                v-if="form.author_type === 'company'"
                                class="grid gap-2 text-sm font-medium"
                                >Founded year<Input
                                    v-model="form.founded_year"
                                    type="number"
                                    min="1900"
                                    :max="new Date().getFullYear()" /></label
                            ><label
                                v-if="form.author_type === 'company'"
                                class="grid gap-2 text-sm font-medium"
                                >Team size<select
                                    v-model="form.team_size"
                                    class="rounded-md border bg-background p-2"
                                >
                                    <option value="">Not specified</option>
                                    <option
                                        v-for="size in professional_options.team_sizes"
                                        :key="size"
                                    >
                                        {{ size }}
                                    </option>
                                </select></label
                            >
                        </div>
                        <label class="grid gap-2 text-sm font-medium"
                            >Confidential work explanation (private, optional
                            with portfolio)<textarea
                                id="confidential_work"
                                :aria-invalid="!!form.errors.confidential_work"
                                aria-describedby="error-confidential_work"
                                v-model="form.confidential_work"
                                maxlength="2000"
                                rows="3"
                                class="rounded-md border bg-background p-3 font-normal"
                                placeholder="If you cannot share client work, explain why and add a professional link below."
                            />
                            <p
                                v-if="form.errors.years_of_experience"
                                id="error-years_of_experience"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.years_of_experience }}
                            </p>
                            <p
                                v-if="form.errors.confidential_work"
                                id="error-confidential_work"
                                class="text-sm font-normal text-destructive"
                            >
                                {{ form.errors.confidential_work }}
                            </p></label
                        >
                    </section>
                    <section
                        id="expertise"
                        class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                    >
                        <h2 class="text-xl font-semibold">
                            Skills & business expertise
                        </h2>
                        <fieldset
                            v-for="kind in [
                                'expertise',
                                'technologies',
                            ] as const"
                            :key="kind"
                        >
                            <legend class="mb-3 text-sm font-medium">
                                {{
                                    kind === 'expertise'
                                        ? 'Business software expertise'
                                        : 'Technologies'
                                }}
                            </legend>
                            <div class="flex flex-wrap gap-3">
                                <label
                                    v-for="item in professional_options[kind]"
                                    :key="item.id"
                                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                    ><input
                                        v-model="
                                            form[
                                                kind === 'expertise'
                                                    ? 'expertise_ids'
                                                    : 'technology_ids'
                                            ]
                                        "
                                        :value="item.id"
                                        type="checkbox"
                                    />{{ item.name }}</label
                                >
                            </div>
                        </fieldset>
                    </section>
                    <section
                        id="links"
                        class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                    >
                        <h2 class="text-xl font-semibold">
                            Links & communication
                        </h2>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label
                                v-for="key in [
                                    'website_url',
                                    'portfolio_url',
                                    'github_url',
                                    'linkedin_url',
                                ] as const"
                                :key="key"
                                class="grid gap-2 text-sm font-medium"
                                >{{
                                    key.replace('_url', '').replace('_', ' ')
                                }}
                                (public HTTPS)<Input
                                    :id="key"
                                    v-model="form[key]"
                                    type="url" /></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Support email (private)<Input
                                    id="support_email"
                                    :aria-invalid="!!form.errors.support_email"
                                    aria-describedby="error-support_email"
                                    v-model="form.support_email"
                                    :disabled="identityLocked"
                                    type="email" /></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Support phone (private)<Input
                                    v-model="form.support_phone"
                                    :disabled="identityLocked"
                                />
                                <p
                                    v-if="form.errors.support_email"
                                    id="error-support_email"
                                    class="text-sm font-normal text-destructive"
                                >
                                    {{ form.errors.support_email }}
                                </p></label
                            >
                        </div>
                        <fieldset>
                            <legend class="mb-3 text-sm font-medium">
                                Communication languages
                            </legend>
                            <div class="flex flex-wrap gap-3">
                                <label
                                    v-for="language in professional_options.languages"
                                    :key="language"
                                    class="flex items-center gap-2 text-sm"
                                    ><input
                                        v-model="form.languages"
                                        :value="language"
                                        type="checkbox"
                                    />{{ language }}</label
                                >
                            </div>
                        </fieldset>
                        <label class="grid gap-2 text-sm font-medium"
                            >Support availability (public, not an SLA)<Input
                                v-model="form.support_availability"
                                maxlength="200"
                                placeholder="Weekdays, India Standard Time"
                        /></label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm"
                                >Region<Input
                                    v-model="form.state_region"
                                    maxlength="100" /></label
                            ><label class="grid gap-2 text-sm"
                                >City<Input v-model="form.city" maxlength="100"
                            /></label>
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.public_location"
                                type="checkbox"
                            />Show country, region and city publicly</label
                        >
                    </section>
                    <div
                        v-if="form.hasErrors || submission.hasErrors"
                        role="alert"
                        class="space-y-2 rounded-xl border border-destructive p-4 text-sm text-destructive"
                    >
                        <p
                            v-for="(error, key) in {
                                ...form.errors,
                                ...submission.errors,
                            }"
                            :key="key"
                        >
                            {{ error }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-4"
                    >
                        <Button :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Save profile'
                        }}</Button
                        ><Button
                            v-if="profile && profile.status !== 'approved'"
                            type="button"
                            variant="outline"
                            :disabled="
                                form.isDirty ||
                                form.processing ||
                                submission.processing
                            "
                            @click="
                                submission.post(onboarding.submit.url(), {
                                    preserveScroll: true,
                                })
                            "
                            >{{
                                profile.status === 'changes_requested'
                                    ? 'Resubmit application'
                                    : 'Submit application'
                            }}</Button
                        ><span class="text-xs text-muted-foreground">{{
                            identityLocked
                                ? 'Professional edits publish immediately.'
                                : 'Save progress before submitting.'
                        }}</span>
                    </div>
                </form>
                <AuthorSnapshot v-else-if="profile" :content="profile" />
                <section
                    v-if="editable && profile"
                    class="space-y-4 rounded-2xl border bg-card p-5 sm:p-7"
                >
                    <h2 class="text-xl font-semibold">Profile imagery</h2>
                    <p class="text-sm text-muted-foreground">
                        Optional PNG, JPEG or WebP; up to 4 MB. Do not upload
                        private verification documents here.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label
                            v-for="purpose in ['avatar', 'cover']"
                            :key="purpose"
                            class="space-y-3 rounded-xl border p-4"
                            ><span class="block text-sm font-medium">{{
                                purpose === 'avatar'
                                    ? 'Avatar / company logo'
                                    : 'Cover image'
                            }}</span
                            ><img
                                v-if="media.find((m) => m.purpose === purpose)"
                                :src="
                                    media.find((m) => m.purpose === purpose)
                                        ?.url
                                "
                                :alt="purpose"
                                class="h-24 w-full rounded-lg object-cover" /><input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="block w-full text-xs"
                                :disabled="upload.processing"
                                @change="image($event, purpose)"
                        /></label>
                    </div>
                    <p
                        v-for="(error, key) in upload.errors"
                        :key="key"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ error }}
                    </p>
                </section>
                <PortfolioEditor
                    v-if="profile"
                    :items="portfolio"
                    :technologies="professional_options.technologies"
                    :revision="profile.revision"
                    :editable="editable"
                />
                <ApplicationHistory v-if="history" :history="history" />
            </main>
        </div>
    </div>
</template>
