<script setup lang="ts">
// Phase 0N.9 (ADR 0047): the platform School lifecycle list -- platform
// metadata only (Confidential). No counts, no tenant data.
interface SchoolSummary {
    id: string;
    name: string;
    slug: string;
    code: string | null;
    status: string;
}

defineProps<{ schools: SchoolSummary[] }>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Schools (platform)</h1>
        <p class="mt-1 text-sm text-slate-600">
            Create a School, then activate, suspend or resume it. Platform access does not include
            access to any School.
        </p>

        <ul v-if="schools.length" class="mt-6 space-y-1 text-sm" data-testid="school-list">
            <li v-for="s in schools" :key="s.id">
                <a class="underline" :href="`/app/platform/schools/${s.id}`">{{ s.name }}</a>
                <span class="ml-2 text-slate-500"
                    >{{ s.slug }}<template v-if="s.code"> · {{ s.code }}</template> ·
                    {{ s.status }}</span
                >
            </li>
        </ul>
        <p v-else class="mt-6 text-sm text-slate-600">No Schools yet.</p>

        <p class="mt-6">
            <a
                class="rounded bg-slate-900 px-3 py-2 text-sm text-white"
                href="/app/platform/schools/create"
                >Create a School</a
            >
        </p>
    </main>
</template>
