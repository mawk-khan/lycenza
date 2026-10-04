<script setup lang="ts">
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * HRX.4 — My Payslips (staff self-service). Only payslips of POSTED payroll
 * runs appear; opening one shows the same printable payslip your School's
 * payroll office sees, with your statutory identifiers masked.
 */
interface OwnPayslip {
    payrollRunId: string;
    employmentRecordId: string;
    runKind: 'regular' | 'correction';
    periodMonth: string;
    paymentDate: string | null;
    postedAt: string | null;
    isReversed: boolean;
}

interface Props {
    available: boolean;
    payslips?: OwnPayslip[];
}

defineProps<Props>();

function month(periodMonth: string): string {
    return new Date(`${periodMonth}T00:00:00`).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">My payslips</h1>

        <EmptyState
            v-if="!available"
            class="mt-8"
            title="No staff record for you in this School"
            description="Your account is not linked to a current employment here. Ask your School administrator."
        />
        <EmptyState
            v-else-if="!payslips || payslips.length === 0"
            class="mt-8"
            title="No payslips yet"
            description="Payslips appear here once your School posts a payroll run."
        />
        <ul v-else class="mt-6 divide-y divide-slate-100 text-sm">
            <li
                v-for="p in payslips"
                :key="`${p.payrollRunId}-${p.employmentRecordId}`"
                class="flex items-center justify-between py-3"
            >
                <span>
                    {{ month(p.periodMonth) }}
                    <span v-if="p.runKind === 'correction'" class="ml-2 text-indigo-700"
                        >Correction</span
                    >
                    <span v-if="p.isReversed" class="ml-2 text-red-700">Reversed</span>
                    <span v-if="p.paymentDate" class="ml-2 text-slate-500"
                        >paid {{ p.paymentDate }}</span
                    >
                </span>
                <a
                    class="underline"
                    :href="`/app/my-payslips/${p.payrollRunId}/${p.employmentRecordId}`"
                    >View</a
                >
            </li>
        </ul>
    </main>
</template>
