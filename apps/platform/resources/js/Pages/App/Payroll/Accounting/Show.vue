<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Configuration {
    id: string;
    salaryExpenseLedgerAccountId: string;
    salaryExpenseLedgerAccountLabel: string | null;
    salaryPayableLedgerAccountId: string;
    salaryPayableLedgerAccountLabel: string | null;
    currency: string;
}

interface Account {
    id: string;
    code: string;
    name: string;
    type: string;
}

interface Props {
    configuration: Configuration | null;
    missingDeductionMappings: Array<{ id: string; code: string; name: string }>;
    accounts: Account[];
}

const props = defineProps<Props>();

const form = useForm({
    salary_expense_ledger_account_id: props.configuration?.salaryExpenseLedgerAccountId ?? '',
    salary_payable_ledger_account_id: props.configuration?.salaryPayableLedgerAccountId ?? '',
});

function submit(): void {
    form.post('/app/payroll/accounting');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <h1 class="mt-2 text-xl font-semibold">Payroll Accounting Configuration</h1>
        <p class="mt-1 text-sm text-slate-500">
            Names the two fixed Finance accounts a normal payroll run posts to. Per-deduction
            liability accounts are set on each Salary Component instead.
        </p>

        <div v-if="configuration" class="mt-4 rounded border border-slate-200 p-4 text-sm">
            <p>Salary expense account: {{ configuration.salaryExpenseLedgerAccountLabel }}</p>
            <p>Salary payable account: {{ configuration.salaryPayableLedgerAccountLabel }}</p>
            <p>Currency: {{ configuration.currency }}</p>
        </div>
        <p v-else class="mt-4 text-sm text-amber-700">
            Not configured yet -- posting will fail until this is set.
        </p>

        <div
            v-if="missingDeductionMappings.length > 0"
            class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
            <p class="font-medium">Missing deduction ledger mappings:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="m in missingDeductionMappings" :key="m.id">
                    {{ m.code }} — {{ m.name }}
                </li>
            </ul>
        </div>

        <form class="mt-6 space-y-3" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="salary_expense_ledger_account_id"
                    >Salary expense account</label
                >
                <select
                    id="salary_expense_ledger_account_id"
                    v-model="form.salary_expense_ledger_account_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select…</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">
                        {{ a.code }} — {{ a.name }} ({{ a.type }})
                    </option>
                </select>
                <p
                    v-if="form.errors.salary_expense_ledger_account_id"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.salary_expense_ledger_account_id }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="salary_payable_ledger_account_id"
                    >Salary payable account</label
                >
                <select
                    id="salary_payable_ledger_account_id"
                    v-model="form.salary_payable_ledger_account_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select…</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">
                        {{ a.code }} — {{ a.name }} ({{ a.type }})
                    </option>
                </select>
                <p
                    v-if="form.errors.salary_payable_ledger_account_id"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.salary_payable_ledger_account_id }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Save configuration' }}
            </button>
        </form>
    </main>
</template>
