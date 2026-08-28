<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface LedgerAccountOption {
    id: string;
    code: string;
    name: string;
    type: string;
}

interface Configuration {
    receivableLedgerAccountId: string;
    revenueLedgerAccountId: string;
}

interface Props {
    configuration: Configuration | null;
    ledgerAccounts: LedgerAccountOption[];
    canManage: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    receivable_ledger_account_id: props.configuration?.receivableLedgerAccountId ?? '',
    revenue_ledger_account_id: props.configuration?.revenueLedgerAccountId ?? '',
});

function submit(): void {
    form.put('/app/canteen-settings');
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Canteen billing settings</h1>
        <p class="mt-1 text-sm text-slate-500">
            The Ledger accounts a fulfilled Canteen Order's Charge is posted against.
        </p>

        <p
            v-if="!configuration"
            class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700"
        >
            Not configured yet. Canteen Orders cannot be fulfilled until a receivable and revenue
            account are selected below.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="receivable_ledger_account_id"
                    >Receivable account</label
                >
                <select
                    id="receivable_ledger_account_id"
                    v-model="form.receivable_ledger_account_id"
                    :disabled="!canManage"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
                >
                    <option value="" disabled>Select an account</option>
                    <option v-for="account in ledgerAccounts" :key="account.id" :value="account.id">
                        {{ account.code }} — {{ account.name }} ({{ account.type }})
                    </option>
                </select>
                <p
                    v-if="form.errors.receivable_ledger_account_id"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.receivable_ledger_account_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="revenue_ledger_account_id"
                    >Revenue account</label
                >
                <select
                    id="revenue_ledger_account_id"
                    v-model="form.revenue_ledger_account_id"
                    :disabled="!canManage"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
                >
                    <option value="" disabled>Select an account</option>
                    <option v-for="account in ledgerAccounts" :key="account.id" :value="account.id">
                        {{ account.code }} — {{ account.name }} ({{ account.type }})
                    </option>
                </select>
                <p v-if="form.errors.revenue_ledger_account_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.revenue_ledger_account_id }}
                </p>
            </div>

            <button
                v-if="canManage"
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Save' }}
            </button>
            <p v-else class="text-sm text-slate-500">
                You have read-only access to these settings.
            </p>
        </form>
    </main>
</template>
