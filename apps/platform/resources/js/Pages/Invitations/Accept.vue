<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Props {
    valid: boolean;
    school?: string;
    token?: string;
    schoolName?: string;
    email?: string;
    accountAlreadyExists?: boolean;
    authenticatedAsMatchingUser?: boolean;
    authenticatedEmail?: string | null;
}

const props = defineProps<Props>();

const form = useForm({
    password: '',
    password_confirmation: '',
});

// The server reports non-field failures (invalid/expired/revoked
// token, existing-account-confirmation-required) under an
// "invitation" key that isn't one of this form's own fields --
// `useForm`'s error typing is derived strictly from the data shape
// above, so it is read through this loosely-typed view instead.
const invitationError = form.errors as Record<string, string>;

function submit(): void {
    form.post(`/invitations/${props.school}/${props.token}`);
}

function confirm(): void {
    form.post(`/invitations/${props.school}/${props.token}`);
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <template v-if="!valid">
            <h1 class="text-xl font-semibold">Invitation not valid</h1>
            <p class="mt-4 text-sm text-slate-600">
                This invitation link is no longer valid. It may have expired, been revoked, or
                already been used. Ask the school to send a new one.
            </p>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold">Join {{ schoolName }} on School OS</h1>
            <p class="mt-2 text-sm text-slate-600">Invitation for {{ email }}</p>

            <!-- Existing account: must already be authenticated as this exact user. -->
            <template v-if="accountAlreadyExists">
                <template v-if="authenticatedAsMatchingUser">
                    <p class="mt-6 text-sm text-slate-600">
                        You're signed in as {{ authenticatedEmail }}. Confirm to link this account
                        to your Guardian record at {{ schoolName }}.
                    </p>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="mt-4 w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        @click="confirm"
                    >
                        Confirm
                    </button>
                </template>
                <template v-else>
                    <p class="mt-6 text-sm text-slate-600">
                        An account already exists for this email. Log in with that account, then
                        return to this link to confirm.
                    </p>
                    <a href="/login" class="mt-4 block text-center underline">Log in</a>
                </template>
                <p v-if="invitationError.invitation" class="mt-4 text-sm text-red-600">
                    {{ invitationError.invitation }}
                </p>
            </template>

            <!-- New account: set a password to activate. -->
            <template v-else>
                <form class="mt-6 space-y-4" @submit.prevent="submit">
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
                        <label class="block text-sm text-slate-600" for="password_confirmation">
                            Confirm password
                        </label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                        />
                    </div>

                    <p v-if="invitationError.invitation" class="text-sm text-red-600">
                        {{ invitationError.invitation }}
                    </p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Create account
                    </button>
                </form>
            </template>
        </template>
    </main>
</template>
