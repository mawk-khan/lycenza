<script setup lang="ts">
// Phase 0O.8A (ADR 0054 amendment): a School-switch sign-in link that is
// expired, already used, meant for another address, or no longer backed by
// access. One generic message on purpose -- never why.
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">This sign-in link cannot be used</h1>
        <p class="mt-4 text-sm" data-testid="handoff-failed">
            It has expired or was already used, or you are signed in here as someone else. Sign in
            again to continue.
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/login">Sign in</a></p>
    </main>
</template>
