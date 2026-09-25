<script setup lang="ts">
// Phase 0N.7: shown instead of a Highly Sensitive platform page when the
// existing MFA requirement is not met -- no factor enrolled
// (mfa_required_not_enrolled) or the sign-in assurance has lapsed
// (mfa_step_up_required). No challenge happens here: assurance comes only
// from the normal sign-in with a code.
defineProps<{ code: string }>();
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Multi-factor authentication required</h1>
        <p
            v-if="code === 'mfa_required_not_enrolled'"
            class="mt-4 text-sm"
            data-testid="mfa-not-enrolled"
        >
            This page is highly sensitive and requires multi-factor authentication. Enroll a factor
            under
            <a class="underline" href="/app/account/security">Account security</a>, then sign out
            and sign in again with a code.
        </p>
        <p v-else class="mt-4 text-sm" data-testid="mfa-step-up">
            Your multi-factor verification has expired. Sign out and sign in again with a code to
            continue.
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/app">Back</a></p>
    </main>
</template>
