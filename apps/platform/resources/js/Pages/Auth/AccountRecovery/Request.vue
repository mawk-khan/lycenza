<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

// Phase 0O.10A (ADR 0056 section 5): one generic confirmation for every
// address -- the page never says whether an account exists.
defineProps<{
    status?: string | null;
}>();

const form = useForm({
    email: '',
});

function submit() {
    form.post('/account-recovery', { onSuccess: () => form.reset('email') });
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-ink">
        <h1 class="text-xl font-semibold">Reset your password</h1>
        <p class="mt-2 text-sm text-ink-muted">
            Enter the email address you sign in with. If it belongs to an eligible account, we will
            send a link to choose a new password.
        </p>

        <p
            v-if="status"
            role="status"
            class="mt-4 rounded-xs border border-control-line bg-graphite-100 px-3 py-2 text-sm text-ink"
            data-testid="recovery-status"
        >
            {{ status }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="input mt-1"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-danger">
                    {{ form.errors.email }}
                </p>
            </div>

            <button type="submit" :disabled="form.processing" class="btn btn-primary w-full">
                Send reset link
            </button>
        </form>

        <p class="mt-4 text-sm">
            <a href="/login" class="text-ink underline">Back to sign in</a>
        </p>
    </main>
</template>
