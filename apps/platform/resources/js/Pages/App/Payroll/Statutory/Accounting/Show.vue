<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Configuration {
    [key: string]: string;
}

interface Account {
    id: string;
    code: string;
    name: string;
    type: string;
}

interface Props {
    configuration: Configuration | null;
    accounts: Account[];
}

const props = defineProps<Props>();

const fields: Array<[string, string]> = [
    ['employeePfPayableLedgerAccountId', 'employee_pf_payable_ledger_account_id'],
    ['employerEpsPayableLedgerAccountId', 'employer_eps_payable_ledger_account_id'],
    ['employerEpfPayableLedgerAccountId', 'employer_epf_payable_ledger_account_id'],
    ['pfAdminChargePayableLedgerAccountId', 'pf_admin_charge_payable_ledger_account_id'],
    ['edliPayableLedgerAccountId', 'edli_payable_ledger_account_id'],
    ['esiPayableLedgerAccountId', 'esi_payable_ledger_account_id'],
    ['tdsPayableLedgerAccountId', 'tds_payable_ledger_account_id'],
    ['professionalTaxPayableLedgerAccountId', 'professional_tax_payable_ledger_account_id'],
    ['lwfPayableLedgerAccountId', 'lwf_payable_ledger_account_id'],
    [
        'employerPfContributionExpenseLedgerAccountId',
        'employer_pf_contribution_expense_ledger_account_id',
    ],
    ['pfAdminChargeExpenseLedgerAccountId', 'pf_admin_charge_expense_ledger_account_id'],
    ['edliExpenseLedgerAccountId', 'edli_expense_ledger_account_id'],
    [
        'employerEsiContributionExpenseLedgerAccountId',
        'employer_esi_contribution_expense_ledger_account_id',
    ],
    [
        'employerLwfContributionExpenseLedgerAccountId',
        'employer_lwf_contribution_expense_ledger_account_id',
    ],
];

const labels: Record<string, string> = {
    employee_pf_payable_ledger_account_id: 'Employee PF payable',
    employer_eps_payable_ledger_account_id: 'Employer EPS payable',
    employer_epf_payable_ledger_account_id: 'Employer EPF payable',
    pf_admin_charge_payable_ledger_account_id: 'PF admin charge payable',
    edli_payable_ledger_account_id: 'EDLI payable',
    esi_payable_ledger_account_id: 'ESI payable',
    tds_payable_ledger_account_id: 'TDS payable',
    professional_tax_payable_ledger_account_id: 'Professional Tax payable',
    lwf_payable_ledger_account_id: 'LWF payable',
    employer_pf_contribution_expense_ledger_account_id: 'Employer PF contribution expense',
    pf_admin_charge_expense_ledger_account_id: 'PF admin charge expense',
    edli_expense_ledger_account_id: 'EDLI expense',
    employer_esi_contribution_expense_ledger_account_id: 'Employer ESI contribution expense',
    employer_lwf_contribution_expense_ledger_account_id: 'Employer LWF contribution expense',
};

const initial: Record<string, string> = {};
for (const [propKey, formKey] of fields) {
    initial[formKey] = props.configuration?.[propKey] ?? '';
}
const form = useForm(initial);

function submit(): void {
    form.post('/app/payroll/statutory/accounting');
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/statutory">← Statutory Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Statutory accounting configuration</h1>
        <p class="mt-1 text-sm text-slate-500">
            Every liability/expense account the Checkpoint 9.6F statutory journal entry posts to. No
            account-name lookup -- these must be real Finance ledger accounts for this School.
        </p>

        <form class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <div v-for="[, formKey] in fields" :key="formKey">
                <label class="block text-sm text-slate-600" :for="formKey">{{
                    labels[formKey]
                }}</label>
                <select
                    :id="formKey"
                    v-model="(form as Record<string, string>)[formKey]"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select…</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">
                        {{ a.code }} — {{ a.name }} ({{ a.type }})
                    </option>
                </select>
                <p v-if="form.errors[formKey]" class="mt-1 text-sm text-red-600">
                    {{ form.errors[formKey] }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="col-span-full mt-2 w-fit rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Save configuration' }}
            </button>
        </form>
    </main>
</template>
