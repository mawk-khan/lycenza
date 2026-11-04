<script setup lang="ts">
import { ref, watch } from 'vue';

interface Result {
    employmentRecordId: string;
    employeeId: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
    status: string;
}

const query = ref('');
const results = ref<Result[]>([]);
const searching = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

watch(query, (value) => {
    if (timer) clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }

    timer = setTimeout(async () => {
        searching.value = true;
        try {
            const response = await fetch(
                `/app/payroll/compensation/search?q=${encodeURIComponent(value)}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );
            const body = await response.json();
            results.value = body.data ?? [];
        } finally {
            searching.value = false;
        }
    }, 300);
});
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Employee Compensation</h1>
        <p class="mt-1 text-sm text-ink-muted">Search by employee name or employee number.</p>

        <input v-model="query" type="text" placeholder="Search…" class="input mt-4 max-w-md" />

        <ul class="mt-4 space-y-1 text-sm">
            <li v-for="r in results" :key="r.employmentRecordId">
                <a class="underline" :href="`/app/payroll/compensation/${r.employmentRecordId}`">
                    {{ r.employeeFullName ?? 'Employee record unavailable' }}
                    <span v-if="r.employeeNumber" class="text-xs text-ink-muted"
                        >({{ r.employeeNumber }})</span
                    >
                </a>
            </li>
        </ul>
        <p
            v-if="query.trim().length >= 2 && !searching && results.length === 0"
            class="mt-4 text-sm text-ink-muted"
        >
            No matching EmploymentRecords.
        </p>
    </main>
</template>
