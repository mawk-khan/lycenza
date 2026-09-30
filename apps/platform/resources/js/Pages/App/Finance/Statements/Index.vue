<script setup lang="ts">
import { ref } from 'vue';

interface StudentOption {
    id: string;
    name: string;
    studentNumber: string | null;
}

const query = ref('');
const results = ref<StudentOption[]>([]);
const searched = ref(false);

async function search(): Promise<void> {
    if (query.value.trim().length < 2) return;
    const response = await fetch(
        `/app/finance/fee-statements/students/search?q=${encodeURIComponent(query.value.trim())}`,
        { headers: { Accept: 'application/json' } },
    );
    results.value = response.ok ? ((await response.json()).data as StudentOption[]) : [];
    searched.value = true;
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Student fee statements</h1>
        <p class="mt-1 text-sm text-slate-500">
            A Student's charges, concessions, payments with their receipt numbers, and what is still
            outstanding -- computed from the ledger records each time it is opened.
        </p>

        <form class="mt-6 flex gap-2" @submit.prevent="search">
            <input
                v-model="query"
                type="search"
                aria-label="Search Students by name or number"
                placeholder="Search by name or Student number"
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
            />
            <button type="submit" class="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                Search
            </button>
        </form>

        <p v-if="searched && results.length === 0" class="mt-4 text-sm text-slate-500">
            No Student matches that search.
        </p>
        <ul v-else class="mt-4 divide-y divide-slate-100 text-sm">
            <li v-for="r in results" :key="r.id" class="py-2">
                <a class="underline" :href="`/app/finance/fee-statements/${r.id}`">{{ r.name }}</a>
                <span v-if="r.studentNumber" class="text-slate-500"> ({{ r.studentNumber }})</span>
            </li>
        </ul>
    </main>
</template>
