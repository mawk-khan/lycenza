<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

interface EmploymentRecord {
    id: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
}

interface PfStatus {
    hasExistingPfMembership: boolean;
    hasUan: boolean;
    hasApprovedHigherWageContribution: boolean;
    higherWageApprovalReference: string | null;
    higherWageApprovalEffectiveFrom: string | null;
    isEpsEligible: boolean;
    hasHigherPensionStatus: boolean;
}

interface EsiPeriod {
    periodStart: string;
    periodEnd: string;
    entryWage: string;
    isCovered: boolean;
    continuous: boolean;
}

interface TaxProfile {
    fiscalYearStart: string;
    regime: string;
    regimeSwitchPolicyReference: string | null;
    previousEmployerIncome: string;
    previousEmployerTds: string;
    declaredOtherIncome: string;
    declaredDeductions: string;
}

interface MaskedIdentifier {
    identifierType: string;
    masked: string;
    updatedAt: string;
}

interface StatutoryResult {
    periodMonth: string;
    isPfExcludedEmployee: boolean;
    employeePfMandatory: string | null;
    employerPfTotal: string | null;
    esiIsCovered: boolean;
    employeeEsi: string | null;
    employerEsi: string | null;
    professionalTax: string | null;
    lwfCharged: boolean;
    employeeLwf: string | null;
    employerLwf: string | null;
    tdsMonthlyDeduction: string | null;
    tdsResidualComplianceException: string | null;
}

interface Props {
    employmentRecord: EmploymentRecord;
    can: { manage: boolean; viewIdentifiers: boolean; manageIdentifiers: boolean };
    pfStatus: PfStatus | null;
    esiCoverage: EsiPeriod[];
    taxProfiles: TaxProfile[];
    identifiers: MaskedIdentifier[];
    statutoryResults: StatutoryResult[];
}

const props = defineProps<Props>();

const pfForm = useForm({
    has_existing_pf_membership: props.pfStatus?.hasExistingPfMembership ?? false,
    has_uan: props.pfStatus?.hasUan ?? false,
    has_approved_higher_wage_contribution:
        props.pfStatus?.hasApprovedHigherWageContribution ?? false,
    higher_wage_approval_reference: props.pfStatus?.higherWageApprovalReference ?? '',
    higher_wage_approval_effective_from: props.pfStatus?.higherWageApprovalEffectiveFrom ?? '',
    is_eps_eligible: props.pfStatus?.isEpsEligible ?? false,
    has_higher_pension_status: props.pfStatus?.hasHigherPensionStatus ?? false,
});
function submitPf(): void {
    pfForm.post(`/app/payroll/statutory/employees/${props.employmentRecord.id}/pf-status`);
}

const esiForm = useForm({
    period_start: '',
    period_end: '',
    entry_wage: '',
    is_covered: true,
});
function submitEsi(): void {
    esiForm.post(`/app/payroll/statutory/employees/${props.employmentRecord.id}/esi-coverage`);
}

const taxForm = useForm({
    fiscal_year_start: '',
    regime: 'new',
    regime_switch_policy_reference: '',
    previous_employer_income: '0.00',
    previous_employer_tds: '0.00',
    declared_other_income: '0.00',
    declared_deductions: '0.00',
});
function submitTax(): void {
    taxForm.post(`/app/payroll/statutory/employees/${props.employmentRecord.id}/tax-profile`);
}

const identifierForm = useForm({
    identifier_type: 'pan',
    value: '',
});
function submitIdentifier(): void {
    identifierForm.post(
        `/app/payroll/statutory/employees/${props.employmentRecord.id}/identifiers`,
    );
}

// Revealed values are fetched ONLY on explicit user action, per
// identifier type, and never preloaded into this page's props -- an
// actor lacking payroll.statutory.identifiers.view can never obtain a
// raw value through devtools because the server never transmits it to
// them at all (mirrors CompensationController's established
// sensitive-value reveal pattern).
const revealed = reactive<Record<string, string | null>>({});
const revealing = ref<string | null>(null);

async function reveal(identifierType: string): Promise<void> {
    revealing.value = identifierType;
    try {
        const response = await fetch(
            `/app/payroll/statutory/employees/${props.employmentRecord.id}/identifiers/${identifierType}/reveal`,
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) return;
        const body = await response.json();
        revealed[identifierType] = body.data?.value ?? null;
    } finally {
        revealing.value = null;
    }
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/statutory">← Statutory Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">
            {{ employmentRecord.employeeFullName }} ({{ employmentRecord.employeeNumber }})
        </h1>

        <!-- PF status -->
        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="font-medium">PF status</h2>
            <form v-if="can.manage" class="mt-3 space-y-2 text-sm" @submit.prevent="submitPf">
                <label class="flex items-center gap-2"
                    ><input v-model="pfForm.has_existing_pf_membership" type="checkbox" /> Has
                    existing PF membership (this or a prior employer)</label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="pfForm.has_uan" type="checkbox" /> Has a UAN</label
                >
                <label class="flex items-center gap-2"
                    ><input
                        v-model="pfForm.has_approved_higher_wage_contribution"
                        type="checkbox"
                    />
                    Approved higher-wage contribution</label
                >
                <div>
                    <label class="block text-slate-600">Higher-wage approval reference</label>
                    <input
                        v-model="pfForm.higher_wage_approval_reference"
                        type="text"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                <div>
                    <label class="block text-slate-600">Higher-wage approval effective from</label>
                    <input
                        v-model="pfForm.higher_wage_approval_effective_from"
                        type="date"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                <label class="flex items-center gap-2"
                    ><input v-model="pfForm.is_eps_eligible" type="checkbox" /> EPS eligible</label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="pfForm.has_higher_pension_status" type="checkbox" /> Higher
                    pension status</label
                >
                <button
                    type="submit"
                    :disabled="pfForm.processing"
                    class="rounded bg-slate-900 px-3 py-2 font-medium text-white disabled:opacity-50"
                >
                    Save PF status
                </button>
            </form>
            <p v-else class="mt-2 text-sm text-slate-500">
                {{ pfStatus ? 'Configured.' : 'Not configured yet.' }} You don't hold
                payroll.statutory.manage.
            </p>
        </section>

        <!-- ESI coverage -->
        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="font-medium">ESI coverage</h2>
            <table v-if="esiCoverage.length > 0" class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-500">
                        <th>Period</th>
                        <th>Entry wage</th>
                        <th>Covered</th>
                        <th>Continuous</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in esiCoverage" :key="p.periodStart">
                        <td>{{ p.periodStart }} – {{ p.periodEnd }}</td>
                        <td>{{ p.entryWage }}</td>
                        <td>{{ p.isCovered ? 'Yes' : 'No' }}</td>
                        <td>{{ p.continuous ? 'Yes' : 'No' }}</td>
                    </tr>
                </tbody>
            </table>
            <form
                v-if="can.manage"
                class="mt-3 grid grid-cols-2 gap-2 text-sm"
                @submit.prevent="submitEsi"
            >
                <input
                    v-model="esiForm.period_start"
                    type="date"
                    placeholder="Period start"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="esiForm.period_end"
                    type="date"
                    placeholder="Period end"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="esiForm.entry_wage"
                    type="text"
                    placeholder="Entry wage"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <label class="flex items-center gap-2"
                    ><input v-model="esiForm.is_covered" type="checkbox" /> Covered</label
                >
                <button
                    type="submit"
                    :disabled="esiForm.processing"
                    class="col-span-2 w-fit rounded bg-slate-900 px-3 py-2 font-medium text-white disabled:opacity-50"
                >
                    Correct/add period
                </button>
                <p v-if="esiForm.errors.period_start" class="col-span-2 text-red-600">
                    {{ esiForm.errors.period_start }}
                </p>
            </form>
        </section>

        <!-- Tax profile -->
        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="font-medium">Tax profile</h2>
            <ul v-if="taxProfiles.length > 0" class="mt-2 space-y-1 text-sm">
                <li v-for="p in taxProfiles" :key="p.fiscalYearStart">
                    FY {{ p.fiscalYearStart }} — {{ p.regime }} regime
                    <span v-if="p.regimeSwitchPolicyReference"
                        >({{ p.regimeSwitchPolicyReference }})</span
                    >
                </li>
            </ul>
            <form
                v-if="can.manage"
                class="mt-3 grid grid-cols-2 gap-2 text-sm"
                @submit.prevent="submitTax"
            >
                <input
                    v-model="taxForm.fiscal_year_start"
                    type="date"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <select v-model="taxForm.regime" class="rounded border border-slate-300 px-3 py-2">
                    <option value="new">New regime</option>
                    <option value="old">Old regime</option>
                </select>
                <input
                    v-model="taxForm.regime_switch_policy_reference"
                    type="text"
                    placeholder="School policy reference (optional)"
                    class="col-span-2 rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="taxForm.previous_employer_income"
                    type="text"
                    placeholder="Previous employer income"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="taxForm.previous_employer_tds"
                    type="text"
                    placeholder="Previous employer TDS"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="taxForm.declared_other_income"
                    type="text"
                    placeholder="Declared other income"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <input
                    v-model="taxForm.declared_deductions"
                    type="text"
                    placeholder="Declared deductions"
                    class="rounded border border-slate-300 px-3 py-2"
                />
                <button
                    type="submit"
                    :disabled="taxForm.processing"
                    class="col-span-2 w-fit rounded bg-slate-900 px-3 py-2 font-medium text-white disabled:opacity-50"
                >
                    Save tax profile
                </button>
            </form>
        </section>

        <!-- Identifiers -->
        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="font-medium">Statutory identifiers</h2>
            <p class="mt-1 text-xs text-slate-500">
                Masked by default. Revealing the real value requires
                payroll.statutory.identifiers.view and is audited.
            </p>
            <table class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-500">
                        <th>Type</th>
                        <th>Value</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in identifiers" :key="i.identifierType">
                        <td>{{ i.identifierType }}</td>
                        <td>{{ revealed[i.identifierType] ?? i.masked }}</td>
                        <td>
                            <button
                                v-if="
                                    can.viewIdentifiers && revealed[i.identifierType] === undefined
                                "
                                type="button"
                                class="underline"
                                :disabled="revealing === i.identifierType"
                                @click="reveal(i.identifierType)"
                            >
                                {{ revealing === i.identifierType ? 'Revealing…' : 'Reveal' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <form
                v-if="can.manageIdentifiers"
                class="mt-3 flex gap-2 text-sm"
                @submit.prevent="submitIdentifier"
            >
                <select
                    v-model="identifierForm.identifier_type"
                    class="rounded border border-slate-300 px-3 py-2"
                >
                    <option value="pan">PAN</option>
                    <option value="uan">UAN</option>
                    <option value="pf_member_id">PF Member ID</option>
                    <option value="esic_ip_number">ESIC IP Number</option>
                </select>
                <input
                    v-model="identifierForm.value"
                    type="text"
                    placeholder="Value"
                    class="flex-1 rounded border border-slate-300 px-3 py-2"
                />
                <button
                    type="submit"
                    :disabled="identifierForm.processing"
                    class="rounded bg-slate-900 px-3 py-2 font-medium text-white disabled:opacity-50"
                >
                    Save
                </button>
            </form>
        </section>

        <!-- Statutory result breakdown -->
        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="font-medium">Statutory result breakdown (most recent runs)</h2>
            <p v-if="statutoryResults.length === 0" class="mt-2 text-sm text-slate-500">
                No finalized statutory calculation yet.
            </p>
            <table v-else class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-500">
                        <th>Period</th>
                        <th>PF (emp/employer)</th>
                        <th>ESI (emp/employer)</th>
                        <th>PT</th>
                        <th>LWF (emp/employer)</th>
                        <th>TDS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in statutoryResults" :key="r.periodMonth">
                        <td>{{ r.periodMonth }}</td>
                        <td>
                            {{
                                r.isPfExcludedEmployee
                                    ? 'excluded'
                                    : `${r.employeePfMandatory} / ${r.employerPfTotal}`
                            }}
                        </td>
                        <td>
                            {{
                                r.esiIsCovered
                                    ? `${r.employeeEsi} / ${r.employerEsi}`
                                    : 'not covered'
                            }}
                        </td>
                        <td>{{ r.professionalTax }}</td>
                        <td>{{ r.lwfCharged ? `${r.employeeLwf} / ${r.employerLwf}` : '—' }}</td>
                        <td>
                            {{ r.tdsMonthlyDeduction }}
                            <span v-if="r.tdsResidualComplianceException" class="text-amber-700">
                                (residual: {{ r.tdsResidualComplianceException }})
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
</template>
