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

// LC-3: what people read in place of internal ids (built by the server).
interface PayslipLabels {
    periodLabel: string;
    runLabel: string;
    correctsPeriodLabel: string | null;
    employeeLabel: string;
}

interface Props {
    payslip: Payslip;
    labels: PayslipLabels;
    /** HRX.4: the own-payslip page links back to My Payslips instead of the run. */
    back?: { href: string; label: string } | null;
}

const props = withDefaults(defineProps<Props>(), { back: null });

const earningLines = props.payslip.lines.filter((l) => l.componentType === 'earning');
const deductionLines = props.payslip.lines.filter((l) => l.componentType === 'deduction');

function print(): void {
    window.print();
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink print:p-0">
        <div class="flex items-start justify-between gap-4 print:hidden">
            <a v-if="back" class="text-sm underline" :href="back.href">← {{ back.label }}</a>
            <a v-else class="text-sm underline" :href="`/app/payroll/runs/${payslip.payrollRunId}`">
                ← Back to run
            </a>
            <button type="button" class="btn btn-primary" @click="print">Print</button>
        </div>

        <p
            v-if="payslip.runKind === 'correction'"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-sm text-accent-strong"
        >
            This is a CORRECTION payslip -- it shows only the delta effect recorded against the
            original run
            <span v-if="labels.correctsPeriodLabel"> ({{ labels.correctsPeriodLabel }})</span>,
            never a merged, replacement statement. The original run's own payslip remains unchanged
            and immutable.
        </p>
        <p
            v-if="payslip.isReversed"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-sm text-ink"
        >
            This posted run has since been REVERSED. This payslip reflects the original, historical,
            immutable result -- it is shown for record purposes and is no longer the School's
            current payment position for this run.
        </p>
        <p
            v-if="!payslip.statutoryDeductionsIncluded"
            class="mt-4 rounded-xs border border-line bg-graphite-100 px-3 py-2 text-xs text-ink-muted"
        >
            Statutory figures (PF/ESI/PT/LWF/TDS) are unavailable for this payslip -- either this
            run predates statutory calculation, statutory calculation was never run for this result,
            or you don't hold the required authority to view them. This is not a
            statutory-compliance document.
        </p>
        <p
            v-if="payslip.statutory"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-xs text-ink"
        >
            ESI disability-threshold provisions are NOT evaluated by this system (deferred, pending
            legal clarification). If this Employee's average daily wage may qualify for the
            disability exemption, verify manually -- do not treat the ESI figures below as legally
            complete for that case.
        </p>

        <div class="mt-6 flex items-start justify-between gap-4 border-b border-control-line pb-4">
            <div>
                <h1 class="text-lg font-semibold">{{ payslip.schoolName }}</h1>
                <p class="text-sm text-ink-muted">Payslip -- {{ labels.periodLabel }}</p>
            </div>
            <div class="text-right text-xs text-ink-muted">
                <p>Run: {{ labels.runLabel }}</p>
                <p v-if="payslip.paymentDate">Payment date: {{ payslip.paymentDate }}</p>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <dt class="text-ink-muted">Employee</dt>
            <dd>
                {{ labels.employeeLabel }}
                <span v-if="payslip.employeeNumber" class="text-xs text-ink-muted"
                    >({{ payslip.employeeNumber }})</span
                >
            </dd>
        </dl>

        <div class="mt-6 grid grid-cols-2 gap-6">
            <section>
                <h2 class="text-sm font-medium text-ink">Earnings</h2>
                <table class="mt-2 w-full text-left text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="l in earningLines" :key="l.salaryComponentId">
                            <td class="py-1.5">{{ l.componentName }}</td>
                            <td class="py-1.5 text-right font-mono">
                                {{ formatMoney(l.amount, '') }}
                            </td>
                        </tr>
                        <tr v-if="earningLines.length === 0">
                            <td class="py-1.5 text-ink-muted" colspan="2">None</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <section>
                <h2 class="text-sm font-medium text-ink">Deductions</h2>
                <table class="mt-2 w-full text-left text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="l in deductionLines" :key="l.salaryComponentId">
                            <td class="py-1.5">{{ l.componentName }}</td>
                            <td class="py-1.5 text-right font-mono">
                                {{ formatMoney(l.amount, '') }}
                            </td>
                        </tr>
                        <tr v-if="deductionLines.length === 0">
                            <td class="py-1.5 text-ink-muted" colspan="2">None</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <section v-if="payslip.statutory" class="mt-6 border-t border-control-line pt-4">
            <h2 class="text-sm font-medium text-ink">Statutory</h2>
            <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                <template v-if="!payslip.statutory.isPfExcludedEmployee">
                    <dt class="text-ink-muted">PF (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeePfMandatory ?? '0.00', '') }}
                    </dd>
                    <dt class="text-ink-muted">PF (employer, informational)</dt>
                    <dd class="text-right font-mono text-ink-muted">
                        {{ formatMoney(payslip.statutory.employerPfTotal ?? '0.00', '') }}
                    </dd>
                </template>
                <template v-if="payslip.statutory.esiIsCovered">
                    <dt class="text-ink-muted">ESI (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeeEsi ?? '0.00', '') }}
                    </dd>
                    <dt class="text-ink-muted">ESI (employer, informational)</dt>
                    <dd class="text-right font-mono text-ink-muted">
                        {{ formatMoney(payslip.statutory.employerEsi ?? '0.00', '') }}
                    </dd>
                </template>
                <dt class="text-ink-muted">Professional Tax</dt>
                <dd class="text-right font-mono">
                    {{ formatMoney(payslip.statutory.professionalTax ?? '0.00', '') }}
                </dd>
                <template v-if="payslip.statutory.lwfCharged">
                    <dt class="text-ink-muted">Labour Welfare Fund (employee)</dt>
                    <dd class="text-right font-mono">
                        {{ formatMoney(payslip.statutory.employeeLwf ?? '0.00', '') }}
                    </dd>
                </template>
                <dt class="text-ink-muted">TDS (this cycle)</dt>
                <dd class="text-right font-mono">
                    {{ formatMoney(payslip.statutory.tdsMonthlyDeduction ?? '0.00', '') }}
                </dd>
            </dl>
            <p
                v-if="payslip.statutory.tdsResidualComplianceException"
                class="mt-2 rounded-xs border border-danger/40 bg-danger-tint px-3 py-2 text-xs text-danger"
            >
                Insufficient available salary to withhold the full TDS due this cycle -- residual
                unresolved amount:
                {{ formatMoney(payslip.statutory.tdsResidualComplianceException, '') }}. This has
                NOT been fabricated as an employer-funded payment; it requires manual compliance
                follow-up.
            </p>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-ink-muted">
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

        <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-1 border-t border-control-line pt-3 text-sm">
            <dt class="text-ink-muted">Gross</dt>
            <dd class="text-right font-mono">{{ formatMoney(payslip.grossAmount, '') }}</dd>
            <dt class="text-ink-muted">Total deductions</dt>
            <dd class="text-right font-mono">{{ formatMoney(payslip.totalDeductions, '') }}</dd>
            <dt class="font-semibold text-ink">Net pay</dt>
            <dd class="text-right font-mono font-semibold">
                {{ formatMoney(payslip.netAmount, '') }}
            </dd>
        </dl>
    </main>
</template>
