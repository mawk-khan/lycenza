<script setup lang="ts">
import { formatMoney } from '../../../../money';

interface PayslipLine {
    salaryComponentId: string;
    componentCode: string;
    componentName: string;
    componentType: 'earning' | 'deduction';
    amount: string;
    effect: 'increase' | 'decrease';
}

interface Payslip {
    schoolId: string;
    schoolName: string;
    payrollRunId: string;
    runKind: 'regular' | 'correction';
    correctsPayrollRunId: string | null;
    correctsPayrollPeriodMonth: string | null;
    runStatus: 'approved' | 'posted';
    isReversed: boolean;
    payrollPeriodId: string;
    periodMonth: string;
    paymentDate: string | null;
    employmentRecordId: string;
    employeeId: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
    grossAmount: string;
    totalDeductions: string;
    netAmount: string;
    lines: PayslipLine[];
    statutoryDeductionsIncluded: boolean;
}

interface Props {
    payslip: Payslip;
}

const props = defineProps<Props>();

const earningLines = props.payslip.lines.filter((l) => l.componentType === 'earning');
const deductionLines = props.payslip.lines.filter((l) => l.componentType === 'deduction');

function print(): void {
    window.print();
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900 print:p-0">
        <div class="flex items-start justify-between gap-4 print:hidden">
            <a class="text-sm underline" :href="`/app/payroll/runs/${payslip.payrollRunId}`">
                ← Back to run
            </a>
            <button
                type="button"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white"
                @click="print"
            >
                Print
            </button>
        </div>

        <p
            v-if="payslip.runKind === 'correction'"
            class="mt-4 rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-800"
        >
            This is a CORRECTION payslip -- it shows only the delta effect recorded against the
            original run
            <span v-if="payslip.correctsPayrollPeriodMonth">
                ({{ payslip.correctsPayrollPeriodMonth }})</span
            >, never a merged, replacement statement. The original run's own payslip remains
            unchanged and immutable.
        </p>
        <p
            v-if="payslip.isReversed"
            class="mt-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
        >
            This posted run has since been REVERSED. This payslip reflects the original, historical,
            immutable result -- it is shown for record purposes and is no longer the School's
            current payment position for this run.
        </p>
        <p
            v-if="!payslip.statutoryDeductionsIncluded"
            class="mt-4 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            Statutory deductions (PF/ESI/TDS) are not calculated by this system yet and are not
            reflected below. This payslip is not a statutory-compliance document.
        </p>

        <div class="mt-6 flex items-start justify-between gap-4 border-b border-slate-300 pb-4">
            <div>
                <h1 class="text-lg font-semibold">{{ payslip.schoolName }}</h1>
                <p class="text-sm text-slate-600">Payslip -- {{ payslip.periodMonth }}</p>
            </div>
            <div class="text-right text-xs text-slate-500">
                <p>
                    Run: <span class="font-mono">{{ payslip.payrollRunId }}</span>
                </p>
                <p v-if="payslip.paymentDate">Payment date: {{ payslip.paymentDate }}</p>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <dt class="text-slate-500">Employee</dt>
            <dd>
                {{ payslip.employeeFullName ?? payslip.employeeId }}
                <span v-if="payslip.employeeNumber" class="text-xs text-slate-500"
                    >({{ payslip.employeeNumber }})</span
                >
            </dd>
        </dl>

        <div class="mt-6 grid grid-cols-2 gap-6">
            <section>
                <h2 class="text-sm font-medium text-slate-900">Earnings</h2>
                <table class="mt-2 w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="l in earningLines" :key="l.salaryComponentId">
                            <td class="py-1.5">{{ l.componentName }}</td>
                            <td class="py-1.5 text-right font-mono">
                                {{ formatMoney(l.amount, '') }}
                            </td>
                        </tr>
                        <tr v-if="earningLines.length === 0">
                            <td class="py-1.5 text-slate-500" colspan="2">None</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <section>
                <h2 class="text-sm font-medium text-slate-900">Deductions</h2>
                <table class="mt-2 w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="l in deductionLines" :key="l.salaryComponentId">
                            <td class="py-1.5">{{ l.componentName }}</td>
                            <td class="py-1.5 text-right font-mono">
                                {{ formatMoney(l.amount, '') }}
                            </td>
                        </tr>
                        <tr v-if="deductionLines.length === 0">
                            <td class="py-1.5 text-slate-500" colspan="2">None</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-1 border-t border-slate-300 pt-3 text-sm">
            <dt class="text-slate-500">Gross</dt>
            <dd class="text-right font-mono">{{ formatMoney(payslip.grossAmount, '') }}</dd>
            <dt class="text-slate-500">Total deductions</dt>
            <dd class="text-right font-mono">{{ formatMoney(payslip.totalDeductions, '') }}</dd>
            <dt class="font-semibold text-slate-900">Net pay</dt>
            <dd class="text-right font-mono font-semibold">
                {{ formatMoney(payslip.netAmount, '') }}
            </dd>
        </dl>
    </main>
</template>
