<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
});

function submit() {
    form.post('/login/mfa');
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Verify your identity</h1>
        <p class="mt-2 text-sm text-slate-600">
            Enter the 6-digit code from your authenticator app, or one of your recovery codes.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="code">Authentication code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    autofocus
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Verify
            </button>
        </form>
    </main>
</template>
