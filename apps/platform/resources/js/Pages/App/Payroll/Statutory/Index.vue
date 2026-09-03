<script setup lang="ts">
import { ref } from 'vue';

interface RuleVersion {
    effectiveFrom?: string;
    legalReference?: string;
}

interface RuleStatus {
    pf: RuleVersion | null;
    esi: (RuleVersion & { disabilityThresholdStatus?: string }) | null;
    professionalTax: unknown | null;
    lwf: unknown | null;
    incomeTaxNewRegime: unknown | null;
    incomeTaxOldRegime: unknown | null;
}

interface Props {
    can: {
        view: boolean;
        manage: boolean;
        viewIdentifiers: boolean;
        manageIdentifiers: boolean;
        generateExports: boolean;
    };
    ruleStatus: RuleStatus | null;
}

defineProps<Props>();

interface SearchResult {
    employmentRecordId: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
}

const query = ref('');
const results = ref<SearchResult[]>([]);
const searching = ref(false);
const runId = ref('');

async function search(): Promise<void> {
    if (query.value.trim().length < 2) {
        results.value = [];
        return;
    }
    searching.value = true;
    try {
        const response = await fetch(
            `/app/payroll/statutory/employees/search?q=${encodeURIComponent(query.value)}`,
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) return;
        const body = await response.json();
        results.value = body.data ?? [];
    } finally {
        searching.value = false;
    }
}

function goToRunExports(): void {
    if (runId.value.trim() === '') return;
    window.location.href = `/app/payroll/statutory/runs/${runId.value.trim()}/exports`;
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Statutory Payroll</h1>
        <p class="mt-1 text-sm text-slate-500">
            PF, ESI, Professional Tax, Labour Welfare Fund, and TDS -- Checkpoint 9.6.
        </p>

        <p v-if="!can.view" class="mt-6 text-sm text-slate-500">
            You don't hold any Statutory Payroll capability yet.
        </p>

        <template v-else>
            <section v-if="ruleStatus" class="mt-6 rounded border border-slate-200 p-4 text-sm">
                <h2 class="font-medium">Active rule versions</h2>
                <dl class="mt-2 grid grid-cols-2 gap-1">
                    <dt class="text-slate-500">PF effective from</dt>
                    <dd>{{ ruleStatus.pf?.effectiveFrom ?? '—' }}</dd>
                    <dt class="text-slate-500">ESI effective from</dt>
                    <dd>{{ ruleStatus.esi?.effectiveFrom ?? '—' }}</dd>
                </dl>
                <p class="mt-2 text-xs text-amber-700">
                    ESI disability threshold: {{ ruleStatus.esi?.disabilityThresholdStatus }}
                </p>
            </section>

            <section class="mt-6">
                <h2 class="font-medium">Manage an Employee's statutory facts</h2>
                <input
                    v-model="query"
                    type="search"
                    placeholder="Search by name or employee number…"
                    class="mt-2 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @input="search"
                />
                <p v-if="searching" class="mt-1 text-xs text-slate-400">Searching…</p>
                <ul v-else-if="results.length > 0" class="mt-2 divide-y divide-slate-100 text-sm">
                    <li v-for="r in results" :key="r.employmentRecordId" class="py-2">
                        <a
                            class="underline"
                            :href="`/app/payroll/statutory/employees/${r.employmentRecordId}`"
                        >
                            {{ r.employeeFullName }} ({{ r.employeeNumber }})
                        </a>
                    </li>
                </ul>
            </section>

            <section v-if="can.manage" class="mt-6">
                <a class="underline text-sm" href="/app/payroll/statutory/accounting"
                    >Statutory accounting configuration</a
                >
            </section>

            <section v-if="can.generateExports" class="mt-6">
                <h2 class="font-medium text-sm">Statutory exports for a Payroll Run</h2>
                <div class="mt-2 flex gap-2">
                    <input
                        v-model="runId"
                        type="text"
                        placeholder="Payroll Run id"
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="button"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white"
                        @click="goToRunExports"
                    >
                        Go
                    </button>
                </div>
            </section>
        </template>
    </main>
</template>
