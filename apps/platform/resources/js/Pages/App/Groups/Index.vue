<script setup lang="ts">
// Phase 0N.5 (ADR 0045): the School Groups this account holds a Group grant
// in -- its own Groups only, never a directory.
defineProps<{ groups: { id: string; name: string }[] }>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Your School Groups</h1>
        <ul v-if="groups.length" class="mt-6 space-y-1 text-sm">
            <li v-for="g in groups" :key="g.id">
                <a class="underline" :href="`/app/groups/${g.id}`">{{ g.name }}</a>
            </li>
        </ul>
        <p v-else class="mt-6 text-sm text-slate-600">
            This account holds no School Group authority.
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/app">Back</a></p>
    </main>
</template>
