<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
defineProps<{ status: string | null }>();
const form = useForm({});
</script>
<template>
    <Head title="Verify your email" />
    <div class="space-y-6">
        <h1 class="text-xl font-semibold">
            Verify your email to become an author
        </h1>
        <p>
            Confirm your account email before starting your marketplace author
            application. Request a verification link and open it from your
            email.
        </p>
        <p
            v-if="status === 'verification-link-sent'"
            role="status"
            class="text-sm text-green-700"
        >
            A verification link has been sent to your account email.
        </p>
        <p
            v-for="(message, key) in form.errors"
            :key="key"
            class="text-destructive"
        >
            {{ message }}
        </p>
        <Button :disabled="form.processing" @click="form.post(send.url())">{{
            form.processing ? 'Sending…' : 'Send Verification Link'
        }}</Button
        ><Link :href="logout()" as="button" class="block underline"
            >Log out</Link
        >
    </div>
</template>
