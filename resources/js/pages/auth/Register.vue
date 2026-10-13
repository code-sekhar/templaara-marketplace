<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: {
        title: 'Create your account',
        description: 'Join Templaara with a customer account',
    },
});
defineProps<{ passwordRules: string }>();
</script>

<template>
    <Head title="Register" />
    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" name="name" required v-focus autocomplete="name" />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="email">Email address</Label>
            <Input
                id="email"
                name="email"
                type="email"
                required
                autocomplete="email"
            />
            <InputError :message="errors.email" />
        </div>
        <div class="grid gap-2">
            <Label for="password">Password</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm password</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password_confirmation" />
        </div>
        <Button type="submit" :disabled="processing"
            ><Spinner v-if="processing" />Create account</Button
        >
        <p class="text-center text-sm text-muted-foreground">
            Already registered? <TextLink :href="login()">Log in</TextLink>
        </p>
    </Form>
</template>
