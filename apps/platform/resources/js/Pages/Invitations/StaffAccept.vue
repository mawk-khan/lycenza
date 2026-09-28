<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { credentialSecretFor, forgetCredentialSecret } from '../../support/recoveryFragment';

// Phase 0O.12B (ADR 0059 sections 6.3, 15): accepting a staff account
// invitation. The SECRET is the link's #fragment (support/recoveryFragment.ts
// captured it before Inertia started); it leaves the browser only in the
// CSRF-protected POST. The page offers both paths side by side -- set a
// password for a new account, or accept as the account you are signed in
// with -- and learns whether an account exists only from the POST's answer.
const props = defineProps<{
    school: string;
    selector: string;
    schoolName: string | null;
    signedInName: string | null;
    invalidMessage: string;
}>();

const SECRET_SHAPE = /^[A-Za-z0-9_-]{43}$/;

const ready = ref(false);
const linkUsable = ref(false);

const form = useForm({
    secret: '',
    mode: 'new' as 'new' | 'existing',
    name: '',
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

function submit(mode: 'new' | 'existing') {
    form.mode = mode;
    form.post(`/invitations/${props.school}/staff/${props.selector}`, {
        onSuccess: () => forgetCredentialSecret(),
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Staff account invitation</h1>
        <p v-if="schoolName" class="mt-2 text-sm text-slate-600">
            You were invited to a staff account at <strong>{{ schoolName }}</strong
            >.
        </p>

        <noscript>
            <p class="mt-4 text-sm text-slate-700">
                This page needs JavaScript to read your invitation link. {{ invalidMessage }}
            </p>
        </noscript>

        <p
            v-if="ready && (!linkUsable || form.errors.secret)"
            role="alert"
            class="mt-4 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
            data-testid="staff-invitation-invalid"
        >
            {{ form.errors.secret || invalidMessage }}
        </p>

        <template v-if="ready && linkUsable && !form.errors.secret">
            <section v-if="signedInName" class="mt-6 rounded border border-slate-200 p-4">
                <h2 class="font-medium">Use your current account</h2>
                <p class="mt-1 text-sm text-slate-600">
                    You are signed in as {{ signedInName }}. If the invitation was sent to this
                    account's address, accept it here.
                </p>
                <button
                    type="button"
                    :disabled="form.processing"
                    class="mt-3 w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    data-testid="accept-existing"
                    @click="submit('existing')"
                >
                    Accept with this account
                </button>
            </section>

            <form class="mt-6 space-y-4" @submit.prevent="submit('new')">
                <h2 class="font-medium">Create your account</h2>
                <p class="text-sm text-slate-600">
                    New here? Choose your name and a password. Already have an account under this
                    address? <a href="/login" class="underline">Sign in</a>, then open the link from
                    your email again.
                </p>
                <div>
                    <label class="block text-sm text-slate-600" for="name">Your name</label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        autocomplete="name"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                        {{ form.errors.name }}
                    </p>
                </div>
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
                    data-testid="accept-new"
                >
                    Create account and accept
                </button>
            </form>
        </template>
    </main>
</template>
