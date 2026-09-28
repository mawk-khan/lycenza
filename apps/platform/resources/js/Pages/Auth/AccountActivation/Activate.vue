<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { credentialSecretFor, forgetCredentialSecret } from '../../../support/recoveryFragment';

// Phase 0O.12B (ADR 0059 sections 5, 10): a bootstrap account's one-time
// activation. The SECRET is the link's #fragment -- never sent by the
// browser on the page GET; app.ts captured it and removed it from the
// address bar before Inertia started (support/recoveryFragment.ts). It is
// kept only in memory and leaves the browser only in the CSRF-protected
// password POST below.
const props = defineProps<{
    selector: string;
    invalidMessage: string;
}>();

const SECRET_SHAPE = /^[A-Za-z0-9_-]{43}$/;

const ready = ref(false);
const linkUsable = ref(false);

const form = useForm({
    secret: '',
    password: '',
    password_confirmation: '',
});

onMounted(() => {
    const secret = credentialSecretFor(props.selector);

    if (SECRET_SHAPE.test(secret)) {
        form.secret = secret;
        linkUsable.value = true;
    }

    ready.value = true;
});

function submit() {
    form.post(`/account-activation/${props.selector}`, {
        onSuccess: () => forgetCredentialSecret(),
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Activate your account</h1>
        <p class="mt-2 text-sm text-slate-600">
            Choose the password you will use to sign in. This link works once.
        </p>

        <noscript>
            <p class="mt-4 text-sm text-slate-700">
                This page needs JavaScript to read your activation link. {{ invalidMessage }}
            </p>
        </noscript>

        <p
            v-if="ready && (!linkUsable || form.errors.secret)"
            role="alert"
            class="mt-4 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
            data-testid="activation-invalid"
        >
            {{ invalidMessage }}
        </p>

        <form
            v-if="ready && linkUsable && !form.errors.secret"
            class="mt-6 space-y-4"
            @submit.prevent="submit"
        >
            <div>
                <label class="block text-sm text-slate-600" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">
                    {{ form.errors.password }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="password_confirmation"
                    >Confirm password</label
                >
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Activate account
            </button>
        </form>
    </main>
</template>
