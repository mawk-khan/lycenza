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
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Reset your password</h1>
        <p class="mt-2 text-sm text-slate-600">
            Enter the email address you sign in with. If it belongs to an eligible account, we will
            send a link to choose a new password.
        </p>

        <p
            v-if="status"
            role="status"
            class="mt-4 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
            data-testid="recovery-status"
        >
            {{ status }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">
                    {{ form.errors.email }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Send reset link
            </button>
        </form>

        <p class="mt-4 text-sm">
            <a href="/login" class="text-slate-700 underline">Back to sign in</a>
        </p>
    </main>
</template>
