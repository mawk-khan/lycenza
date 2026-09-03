<script setup lang="ts">
interface Props {
    can: {
        viewStructures: boolean;
        manageStructures: boolean;
        viewCompensation: boolean;
        manageAccounting: boolean;
        managePeriods: boolean;
        viewRuns: boolean;
        viewStatutory: boolean;
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Payroll</h1>

        <nav class="mt-6">
            <ul class="space-y-1 text-sm">
                <li v-if="can.viewStructures">
                    <a class="underline" href="/app/payroll/components">Salary Components</a>
                </li>
                <li v-if="can.viewStructures">
                    <a class="underline" href="/app/payroll/structures">Salary Structures</a>
                </li>
                <li v-if="can.viewCompensation">
                    <a class="underline" href="/app/payroll/compensation">Employee Compensation</a>
                </li>
                <li v-if="can.managePeriods">
                    <a class="underline" href="/app/payroll/periods">Payroll Periods &amp; Runs</a>
                </li>
                <li v-if="can.manageAccounting">
                    <a class="underline" href="/app/payroll/accounting"
                        >Payroll Accounting Configuration</a
                    >
                </li>
                <li v-if="can.viewStatutory">
                    <a class="underline" href="/app/payroll/statutory">Statutory Payroll</a>
                </li>
            </ul>
        </nav>

        <p
            v-if="
                !can.viewStructures &&
                !can.viewCompensation &&
                !can.managePeriods &&
                !can.manageAccounting
            "
            class="mt-6 text-sm text-slate-500"
        >
            You don't hold any Payroll capability yet.
        </p>
    </main>
</template>
