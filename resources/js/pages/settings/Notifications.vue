<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/notifications/preferences';
import type { NotificationPreference } from '@/types/notifications';
const props = defineProps<{ preferences: NotificationPreference[] }>();
const form = useForm({
    preferences: props.preferences.map(
        ({ group, in_app_enabled, email_enabled }) => ({
            group,
            in_app_enabled,
            email_enabled,
        }),
    ),
});
function errorAt(key: string) {
    return (form.errors as Record<string, string>)[key];
}
function save() {
    form.put(update.url(), { preserveScroll: true });
}
</script>
<template>
    <Head title="Notification preferences" />
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notification preferences"
            description="Choose which marketplace updates appear in-app and by email."
        />
        <p class="text-sm text-muted-foreground">
            Purchase-ready, refund-completed and payout-paid confirmations
            always appear in-app. Security and account emails are managed
            separately. Email is sent only for important updates within each
            enabled group.
        </p>
        <form class="space-y-4" @submit.prevent="save">
            <fieldset
                v-for="(pref, i) in preferences"
                :key="pref.group"
                class="space-y-3 rounded-lg border p-4"
            >
                <legend class="px-1 font-medium">{{ pref.label }}</legend>
                <label class="flex items-center gap-3 text-sm"
                    ><input
                        v-model="form.preferences[i].in_app_enabled"
                        type="checkbox"
                        class="size-4"
                    />In-app updates</label
                >
                <label class="flex items-center gap-3 text-sm"
                    ><input
                        v-model="form.preferences[i].email_enabled"
                        type="checkbox"
                        class="size-4"
                    />Important email updates</label
                >
                <p
                    v-if="pref.mandatory_in_app"
                    class="text-xs text-muted-foreground"
                >
                    Essential completion confirmations remain enabled in-app.
                </p>
                <p
                    v-if="pref.group === 'earnings'"
                    class="text-xs text-muted-foreground"
                >
                    Earning updates are in-app only in this phase.
                </p>
                <InputError :message="errorAt(`preferences.${i}.group`)" />
            </fieldset>
            <InputError :message="form.errors.preferences" />
            <Button :disabled="form.processing">{{
                form.processing ? 'Saving…' : 'Save preferences'
            }}</Button>
        </form>
    </div>
</template>
