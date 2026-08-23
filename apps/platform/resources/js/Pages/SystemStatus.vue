<script setup lang="ts">
interface Props {
    appName: string;
    environment: string;
    phase: string;
    requestId: string | null;
    plannedModules: string[];
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-2xl font-semibold">{{ appName }}</h1>
        <p class="mt-1 text-slate-500">{{ phase }}</p>

        <dl class="mt-6 grid grid-cols-[8rem_1fr] gap-y-2 text-sm">
            <dt class="text-slate-500">Environment</dt>
            <dd>{{ environment }}</dd>
            <dt class="text-slate-500">Request ID</dt>
            <dd>{{ requestId ?? 'n/a' }}</dd>
        </dl>

        <h2 class="mt-8 text-sm font-medium text-slate-500">
            Planned modules (not yet implemented)
        </h2>
        <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <li v-for="mod in plannedModules" :key="mod">{{ mod }}</li>
        </ul>
    </main>
</template>
