<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { countryCodes } from '@/lib/invoicing';
import { useAuthorization } from '@/composables/useAuthorization';
import * as routes from '@/routes/admin/marketplace-settings';
type Value = string | number | boolean | string[] | null;
type Setting = {
    value: Value;
    version: number;
    type: string;
    description: string;
    snapshot: boolean;
};
const props = defineProps<{
    sections: Record<string, Record<string, Setting>>;
    history: {
        group: string;
        key: string;
        actor_name: string;
        old_value: Value;
        new_value: Value;
        occurred_at: string;
    }[];
}>();
const { can } = useAuthorization();
const selected = ref('general');
const notice = ref('');
const labels: Record<string, string> = {
    general: 'General',
    commerce: 'Commerce',
    authors: 'Authors & Commission',
    payouts: 'Payouts',
    refunds: 'Refunds',
    products: 'Products',
    support: 'Support',
    notifications: 'Notifications',
    reporting: 'Reporting',
    invoicing: 'Invoicing & Tax',
};
const forms = Object.fromEntries(
    Object.entries(props.sections).map(([group, fields]) => [
        group,
        useForm({
            values: Object.fromEntries(
                Object.entries(fields).map(([key, s]) => [key, s.value]),
            ),
            versions: Object.fromEntries(
                Object.entries(fields).map(([key, s]) => [key, s.version]),
            ),
        }),
    ]),
);
const choices = (key: string) =>
    key === 'enabled_currencies'
        ? ['INR', 'USD', 'EUR', 'GBP']
        : key === 'international_countries'
          ? countryCodes
          : ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'txt', 'log'];
function toggle(group: string, key: string, value: string, checked: boolean) {
    const list = forms[group].values[key] as string[];
    forms[group].values[key] = checked
        ? [...list, value]
        : list.filter((v) => v !== value);
}
function save(group: string) {
    notice.value = '';
    forms[group].put(routes.update(group).url, {
        preserveScroll: true,
        onSuccess: () => {
            for (const [key, s] of Object.entries(props.sections[group]))
                forms[group].versions[key] = s.version;
            notice.value = `${labels[group]} settings saved.`;
        },
    });
}
const title = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (s) => s.toUpperCase());
</script>
<template>
    <Head title="Marketplace settings" />
    <main class="space-y-6 p-4 md:p-8">
        <h1 class="text-2xl font-semibold">Marketplace settings</h1>
        <p class="text-muted-foreground">
            Manage business configuration. Purchase snapshots and retained
            history stay unchanged. Secrets remain environment configuration.
        </p>
        <p v-if="notice" role="status" class="rounded border p-3">
            {{ notice }}
        </p>
        <p v-if="!can('settings.manage')" class="rounded border p-3">
            You have read-only access.
        </p>
        <div class="grid gap-6 md:grid-cols-[220px_1fr]">
            <nav
                aria-label="Settings sections"
                class="flex flex-wrap gap-2 self-start md:sticky md:top-6 md:flex-col"
            >
                <Button
                    v-for="group in Object.keys(sections)"
                    :key="group"
                    :variant="selected === group ? 'default' : 'outline'"
                    :aria-current="selected === group ? 'page' : undefined"
                    @click="selected = group"
                    >{{ labels[group] }}</Button
                >
            </nav>
            <section
                v-for="(fields, group) in sections"
                v-show="selected === group"
                :key="group"
                class="min-w-0 rounded-lg border p-5"
            >
                <h2 class="mb-5 text-xl font-medium">{{ labels[group] }}</h2>
                <form class="space-y-5" @submit.prevent="save(group)">
                    <div
                        v-for="(setting, key) in fields"
                        :key="key"
                        class="space-y-2"
                    >
                        <Label :for="`${group}-${key}`">{{ title(key) }}</Label>
                        <fieldset
                            v-if="setting.type === 'list'"
                            :id="`${group}-${key}`"
                            :disabled="!can('settings.manage')"
                            class="flex flex-wrap gap-4"
                        >
                            <legend class="sr-only">{{ title(key) }}</legend>
                            <label
                                v-for="option in choices(key)"
                                :key="option"
                                class="flex items-center gap-2"
                                ><input
                                    type="checkbox"
                                    :checked="
                                        (
                                            forms[group].values[key] as string[]
                                        ).includes(option)
                                    "
                                    @change="
                                        toggle(
                                            group,
                                            key,
                                            option,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />{{ option }}</label
                            >
                        </fieldset>
                        <input
                            v-else-if="setting.type === 'boolean'"
                            :id="`${group}-${key}`"
                            v-model="forms[group].values[key]"
                            type="checkbox"
                            :disabled="!can('settings.manage')"
                        />
                        <Input
                            v-else
                            :id="`${group}-${key}`"
                            v-model="
                                forms[group].values[key] as
                                    string | number | undefined
                            "
                            :type="
                                setting.type === 'integer'
                                    ? 'number'
                                    : setting.type === 'email'
                                      ? 'email'
                                      : 'text'
                            "
                            :disabled="!can('settings.manage')"
                            :aria-describedby="`${group}-${key}-help`"
                        />
                        <p
                            :id="`${group}-${key}-help`"
                            class="text-sm text-muted-foreground"
                        >
                            {{ setting.description }}
                        </p>
                        <p v-if="setting.snapshot" class="text-sm font-medium">
                            Applies to future purchases only.
                        </p>
                        <InputError
                            :message="
                                forms[group].errors[
                                    `values.${key}` as keyof (typeof forms)[typeof group]['errors']
                                ]
                            "
                        />
                    </div>
                    <InputError :message="forms[group].errors.values" />
                    <Button
                        v-if="can('settings.manage')"
                        type="submit"
                        :disabled="forms[group].processing"
                        >{{
                            forms[group].processing
                                ? 'Saving…'
                                : `Save ${labels[group]}`
                        }}</Button
                    >
                </form>
            </section>
        </div>
        <section class="space-y-3">
            <h2 class="text-xl font-medium">Recent changes</h2>
            <p v-if="!history.length" class="text-muted-foreground">
                No settings overrides have been changed.
            </p>
            <ol class="space-y-2">
                <li
                    v-for="(event, i) in history"
                    :key="i"
                    class="rounded border p-3 text-sm break-words"
                >
                    {{ event.actor_name }} changed {{ event.group }} /
                    {{ title(event.key) }} from
                    {{ JSON.stringify(event.old_value) }} to
                    {{ JSON.stringify(event.new_value) }} ·
                    {{ event.occurred_at }}
                </li>
            </ol>
        </section>
    </main>
</template>
