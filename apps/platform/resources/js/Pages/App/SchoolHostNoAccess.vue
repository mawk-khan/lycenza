<script setup lang="ts">
// Phase 0O.8A (ADR 0054 section 8.5): this web address belongs to one School
// and the signed-in account has no active access to it. A domain never grants
// access; nothing here switches School or carries the session elsewhere --
// the platform address has its own, separate sign-in.
import { router } from '@inertiajs/vue3';

defineProps<{ platformUrl: string }>();

function signOut() {
    router.post('/logout');
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">No access to this School</h1>
        <p class="mt-4 text-sm" data-testid="school-host-no-access">
            You are signed in, but your account does not have access to the School at this web
            address.
        </p>
        <p class="mt-4 text-sm">
            <button class="underline" type="button" @click="signOut">Sign out</button>
            · <a class="underline" :href="platformUrl">Go to the main Lycenza address</a>
        </p>
    </main>
</template>
