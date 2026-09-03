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

interface PayslipStatutorySection {
    isPfExcludedEmployee: boolean;
    employeePfMandatory: string | null;
    employeePfVoluntary: string | null;
    employerPfTotal: string | null;
    employerEps: string | null;
    employerEpf: string | null;
    employerEdli: string | null;
    esiIsCovered: boolean;
    employeeEsi: string | null;
    employerEsi: string | null;
    professionalTax: string | null;
    lwfCharged: boolean;
    employeeLwf: string | null;
    employerLwf: string | null;
    tdsMonthlyDeduction: string | null;
    tdsResidualComplianceException: string | null;
    maskedPan: string | null;
    maskedUan: string | null;
    maskedPfMemberId: string | null;
    maskedEsicIpNumber: string | null;
    esiDisabilityProvisionsEvaluated: boolean;
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
    statutory: PayslipStatutorySection | null;
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
            Statutory figures (PF/ESI/PT/LWF/TDS) are unavailable for this payslip -- either this
            run predates statutory calculation, statutory calculation was never run for this result,
            or you don't hold the required authority to view them. This is not a
            statutory-compliance document.
        </p>
        <p
            v-if="payslip.statutory"
            class="mt-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
        >
            ESI disability-threshold provisions are NOT evaluated by this system (deferred, pending
            legal clarification). If this Employee's average daily wage may qualify for the
            disability exemption, verify manually -- do not treat the ESI figures below as legally
            complete for that case.
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

        <section v-if="payslip.statutory" class="mt-6 border-t border-slate-300 pt-4">
            <h2 class="text-sm font-medium text-slate-900">Statutory</h2>
            <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                <template v-if="!payslip.statutory.isPfExcludedEmployee">
                    <dt class="text-slate-500">PF (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeePfMandatory ?? '0.00', '') }}
                    </dd>
                    <dt class="text-slate-500">PF (employer, informational)</dt>
                    <dd class="text-right font-mono text-slate-500">
                        {{ formatMoney(payslip.statutory.employerPfTotal ?? '0.00', '') }}
                    </dd>
                </template>
                <template v-if="payslip.statutory.esiIsCovered">
                    <dt class="text-slate-500">ESI (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeeEsi ?? '0.00', '') }}
                    </dd>
                    <dt class="text-slate-500">ESI (employer, informational)</dt>
                    <dd class="text-right font-mono text-slate-500">
                        {{ formatMoney(payslip.statutory.employerEsi ?? '0.00', '') }}
                    </dd>
                </template>
                <dt class="text-slate-500">Professional Tax</dt>
                <dd class="text-right font-mono">
                    {{ formatMoney(payslip.statutory.professionalTax ?? '0.00', '') }}
                </dd>
                <template v-if="payslip.statutory.lwfCharged">
                    <dt class="text-slate-500">Labour Welfare Fund (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeeLwf ?? '0.00', '') }}
                    </dd>
                </template>
                <dt class="text-slate-500">TDS (this cycle)</dt>
                <dd class="text-right font-mono">
                    {{ formatMoney(payslip.statutory.tdsMonthlyDeduction ?? '0.00', '') }}
                </dd>
            </dl>
            <p
                v-if="payslip.statutory.tdsResidualComplianceException"
                class="mt-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800"
            >
                Insufficient available salary to withhold the full TDS due this cycle -- residual
                unresolved amount:
                {{ formatMoney(payslip.statutory.tdsResidualComplianceException, '') }}. This has
                NOT been fabricated as an employer-funded payment; it requires manual compliance
                follow-up.
            </p>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-500">
                <template v-if="payslip.statutory.maskedPan">
                    <dt>PAN</dt>
                    <dd class="text-right font-mono">{{ payslip.statutory.maskedPan }}</dd>
                </template>
                <template v-if="payslip.statutory.maskedUan">
                    <dt>UAN</dt>
                    <dd class="text-right font-mono">{{ payslip.statutory.maskedUan }}</dd>
                </template>
                <template v-if="payslip.statutory.maskedPfMemberId">
                    <dt>PF Member ID</dt>
                    <dd class="text-right font-mono">{{ payslip.statutory.maskedPfMemberId }}</dd>
                </template>
                <template v-if="payslip.statutory.maskedEsicIpNumber">
                    <dt>ESIC IP Number</dt>
                    <dd class="text-right font-mono">{{ payslip.statutory.maskedEsicIpNumber }}</dd>
                </template>
            </dl>
        </section>

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
