<script setup lang="ts">
interface Props {
    can: {
        viewLedger: boolean;
        postLedger: boolean;
        viewCharges: boolean;
        manageCharges: boolean;
        viewPayments: boolean;
        recordPayments: boolean;
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Finance</h1>
        <p class="mt-1 text-sm text-slate-500">
            Ledger accounts and journal entries, Fee charges, and Payment records.
        </p>

        <ul class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-if="can.viewLedger" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/ledger-accounts"
                    >Ledger accounts</a
                >
                <p class="mt-1 text-sm text-slate-500">The Chart of Accounts directory.</p>
            </li>
            <li v-if="can.viewLedger" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/journal-entries"
                    >Journal entries</a
                >
                <p class="mt-1 text-sm text-slate-500">
                    Posted ledger history{{ can.postLedger ? ', and post new entries' : '' }}.
                </p>
            </li>
            <li v-if="can.viewCharges" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/charges">Charges</a>
                <p class="mt-1 text-sm text-slate-500">
                    Student fee charges{{ can.manageCharges ? ', assess and cancel' : '' }}.
                </p>
            </li>
            <li v-if="can.viewPayments" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/payments">Payments</a>
                <p class="mt-1 text-sm text-slate-500">
                    Payment records and how each was applied to charges.
                </p>
            </li>
            <li v-if="can.recordPayments" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/payments/record"
                    >Record an offline payment</a
                >
                <p class="mt-1 text-sm text-slate-500">
                    Record cash, bank transfer or cheque money the School has already received.
                </p>
            </li>
        </ul>

        <p
            v-if="!can.viewLedger && !can.viewCharges && !can.viewPayments && !can.recordPayments"
            class="mt-6 text-sm text-slate-500"
        >
            You don't have access to any Finance area yet.
        </p>
    </main>
</template>
