<script setup lang="ts">
interface Props {
    can: {
        viewLedger: boolean;
        postLedger: boolean;
        closePeriods: boolean;
        viewCharges: boolean;
        manageCharges: boolean;
        viewPayments: boolean;
        recordPayments: boolean;
        viewFeeSetup: boolean;
        viewFeeRuns: boolean;
        viewConcessions: boolean;
        viewPaymentCorrections: boolean;
        viewStatements: boolean;
        viewLateFeeRules: boolean;
        viewLateFeeRuns: boolean;
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Finance</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Ledger accounts and journal entries, fee setup, Fee charges, and Payment records.
        </p>

        <ul class="mt-6 divide-y divide-line rounded-xs border border-line">
            <li v-if="can.viewLedger" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/ledger-accounts"
                    >Ledger accounts</a
                >
                <p class="mt-1 text-sm text-ink-muted">The Chart of Accounts directory.</p>
            </li>
            <li v-if="can.viewLedger" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/journal-entries"
                    >Journal entries</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Posted ledger history{{ can.postLedger ? ', and post new entries' : '' }}.
                </p>
            </li>
            <li v-if="can.viewLedger" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/periods"
                    >Financial periods</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Financial years and their close status{{
                        can.closePeriods ? ', and close a finished year' : ''
                    }}.
                </p>
            </li>
            <li v-if="can.viewFeeSetup" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/fee-setup">Fee setup</a>
                <p class="mt-1 text-sm text-ink-muted">
                    Fee heads, fee structures by year and grade, instalment schedules and optional
                    fees.
                </p>
            </li>
            <li v-if="can.viewFeeRuns" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/fee-runs"
                    >Fee assessment runs</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Preview and bill a fee structure's billing period to its Students.
                </p>
            </li>
            <li v-if="can.viewLateFeeRules || can.viewLateFeeRuns" class="px-4 py-3">
                <a
                    v-if="can.viewLateFeeRules"
                    class="text-sm font-medium underline"
                    href="/app/finance/late-fees"
                    >Late-fee rules</a
                >
                <a
                    v-if="can.viewLateFeeRuns"
                    class="ml-3 text-sm font-medium underline"
                    href="/app/finance/late-fee-runs"
                    >Late-fee runs</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    One late fee per overdue charge per rule, after its grace days. No recurring or
                    compounding charges.
                </p>
            </li>
            <li v-if="can.viewConcessions" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/concessions"
                    >Concessions</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Concessions, scholarships and waivers: requests, the approval queue and posted
                    adjustments.
                </p>
            </li>
            <li v-if="can.viewStatements" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/outstanding"
                    >Outstanding fees</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Students who still owe money -- outstanding and overdue -- for follow-up.
                </p>
            </li>
            <li v-if="can.viewStatements" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/fee-statements"
                    >Student fee statements</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    One Student's charges, concessions, payments, receipts and outstanding balance.
                </p>
            </li>
            <li v-if="can.viewCharges" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/charges">Charges</a>
                <p class="mt-1 text-sm text-ink-muted">
                    Student fee charges{{ can.manageCharges ? ', assess and cancel' : '' }}.
                </p>
            </li>
            <li v-if="can.viewPayments" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/payments">Payments</a>
                <p class="mt-1 text-sm text-ink-muted">
                    Payment records and how each was applied to charges.
                </p>
            </li>
            <li v-if="can.viewPaymentCorrections" class="px-4 py-3">
                <a
                    class="text-sm font-medium underline"
                    href="/app/finance/payment-correction-requests"
                    >Payment corrections</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Requested reallocations, reversals and refunds: each waits for someone else's
                    approval, which records it.
                </p>
            </li>
            <li v-if="can.recordPayments" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/finance/payments/record"
                    >Record an offline payment</a
                >
                <p class="mt-1 text-sm text-ink-muted">
                    Record cash, bank transfer or cheque money the School has already received.
                </p>
            </li>
        </ul>

        <p
            v-if="
                !can.viewLedger &&
                !can.viewCharges &&
                !can.viewPayments &&
                !can.recordPayments &&
                !can.viewFeeSetup &&
                !can.viewConcessions &&
                !can.viewPaymentCorrections
            "
            class="mt-6 text-sm text-ink-muted"
        >
            You don't have access to any Finance area yet.
        </p>
    </main>
</template>
