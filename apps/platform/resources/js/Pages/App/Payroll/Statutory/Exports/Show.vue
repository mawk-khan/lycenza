<script setup lang="ts">
interface Props {
    payrollRunId: string;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/statutory">← Statutory Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Statutory exports</h1>
        <p class="mt-1 text-sm text-slate-500">
            Data preparation only -- no government portal submission. Verify every value before any
            real filing.
        </p>

        <ul class="mt-6 space-y-2 text-sm">
            <li>
                <a
                    class="underline"
                    :href="`/app/payroll/statutory/runs/${payrollRunId}/exports/ecr`"
                    >EPFO ECR (11-field, #~#-delimited)</a
                >
            </li>
            <li>
                <a
                    class="underline"
                    :href="`/app/payroll/statutory/runs/${payrollRunId}/exports/esi-worksheet`"
                    >ESIC monthly contribution worksheet (CSV)</a
                >
            </li>
            <li>
                <a
                    class="underline"
                    :href="`/app/payroll/statutory/runs/${payrollRunId}/exports/tds-draft-statement`"
                    >Form 138 draft TDS statement (CSV, April 2026+ periods only)</a
                >
            </li>
        </ul>
    </main>
</template>
