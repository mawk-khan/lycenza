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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Statutory Payroll</h1>
        <p class="mt-1 text-sm text-ink-muted">
            PF, ESI, Professional Tax, Labour Welfare Fund and TDS.
        </p>

        <p v-if="!can.view" class="mt-6 text-sm text-ink-muted">
            You don't hold any Statutory Payroll capability yet.
        </p>

        <template v-else>
            <section v-if="ruleStatus" class="mt-6 rounded-xs border border-line p-4 text-sm">
                <h2 class="font-medium">Active rule versions</h2>
                <dl class="mt-2 grid grid-cols-2 gap-1">
                    <dt class="text-ink-muted">PF effective from</dt>
                    <dd>{{ ruleStatus.pf?.effectiveFrom ?? '—' }}</dd>
                    <dt class="text-ink-muted">ESI effective from</dt>
                    <dd>{{ ruleStatus.esi?.effectiveFrom ?? '—' }}</dd>
                </dl>
                <p class="mt-2 text-xs text-ink">
                    ESI disability threshold: {{ ruleStatus.esi?.disabilityThresholdStatus }}
                </p>
            </section>

            <section class="mt-6">
                <h2 class="font-medium">Manage an Employee's statutory facts</h2>
                <input
                    v-model="query"
                    type="search"
                    placeholder="Search by name or employee number…"
                    class="input mt-2"
                    @input="search"
                />
                <p v-if="searching" class="mt-1 text-xs text-ink-muted">Searching…</p>
                <ul v-else-if="results.length > 0" class="mt-2 divide-y divide-line text-sm">
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
                    <input v-model="runId" type="text" placeholder="Payroll Run id" class="input" />
                    <button type="button" class="btn btn-primary" @click="goToRunExports">
                        Go
                    </button>
                </div>
            </section>
        </template>
    </main>
</template>
