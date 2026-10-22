<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
import ChargeEndForm from '../../../../Components/ChargeEndForm.vue';
import { formatMoney } from '../../../../money';

interface ChargeDetail {
    id: string;
    studentId: string;
    studentName: string | null;
    studentNumber: string | null;
    academicYearId: string;
    academicYearName: string | null;
    description: string;
    amount: string;
    currency: string;
    dueDate: string | null;
    receivableLedgerAccountId: string | null;
    revenueLedgerAccountId: string | null;
    journalEntryId: string | null;
    cancelledAt: string | null;
    cancellationJournalEntryId: string | null;
    createdAt: string;
}

interface Adjustment {
    id: string;
    feeConcessionId: string;
    category: string;
    amount: string;
    currency: string;
    postedAt: string;
    cancelledAt: string | null;
}

interface Props {
    charge: ChargeDetail;
    canManage: boolean;
    canRecordPayment: boolean;
    adjustments: Adjustment[] | null;
    canRequestConcession: boolean;
    canViewStatement: boolean;
    lateFees: {
        raised: Array<{ assessmentId: string; chargeId: string; voided: boolean }>;
        sourceChargeId: string | null;
    };
    payments: Array<{ paymentId: string; amount: string }> | null;
    /** ORG.7G-1: the amount on this charge from payments a Campus reader cannot open. */
    otherPaymentsAmount: string | null;
    /** ORG.8G: the listed cancel reasons and whether a fresh code can be given. */
    cancelReasons: string[];
    hasMfaFactor: boolean;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/charges">← Charges</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ charge.description }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ charge.studentName ?? 'Student unavailable' }}
                    <span v-if="charge.studentNumber">({{ charge.studentNumber }})</span>
                    · {{ charge.academicYearName ?? 'Academic year unavailable' }}
                </p>
                <a
                    v-if="canViewStatement"
                    class="mt-1 inline-block text-sm underline"
                    :href="`/app/finance/fee-statements/${charge.studentId}`"
                    >Student fee statement</a
                >
            </div>
            <span
                v-if="charge.cancelledAt"
                class="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                >Cancelled</span
            >
            <span
                v-else
                class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                >Assessed</span
            >
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Amount</dt>
                <dd class="font-mono">{{ formatMoney(charge.amount, charge.currency) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Due date</dt>
                <dd>{{ charge.dueDate ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Assessed</dt>
                <dd>{{ formatDateTime(charge.createdAt) }}</dd>
            </div>
            <div v-if="charge.journalEntryId">
                <dt class="text-slate-500">Recognition journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${charge.journalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
            <div v-if="charge.cancelledAt">
                <dt class="text-slate-500">Cancelled</dt>
                <dd>{{ formatDateTime(charge.cancelledAt) }}</dd>
            </div>
            <div v-if="charge.cancellationJournalEntryId">
                <dt class="text-slate-500">Cancellation journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${charge.cancellationJournalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
        </dl>

        <section
            v-if="lateFees.sourceChargeId || lateFees.raised.length"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Late fees</h2>
            <p v-if="lateFees.sourceChargeId" class="mt-1 text-sm">
                This charge is a late fee on
                <a class="underline" :href="`/app/finance/charges/${lateFees.sourceChargeId}`"
                    >its source charge</a
                >. It is a separate charge; the source charge is unchanged.
            </p>
            <ul v-if="lateFees.raised.length" class="mt-1 text-sm">
                <li v-for="l in lateFees.raised" :key="l.assessmentId">
                    <a class="underline" :href="`/app/finance/charges/${l.chargeId}`"
                        >Late-fee charge</a
                    >
                    <span v-if="l.voided" class="text-slate-500"> (voided)</span>
                </li>
            </ul>
        </section>

        <section v-if="adjustments !== null" class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Concession adjustments</h2>
            <p v-if="adjustments.length === 0" class="mt-1 text-sm text-slate-500">
                No concession has been posted against this charge.
            </p>
            <ul v-else class="mt-2 divide-y divide-slate-100 text-sm">
                <li
                    v-for="a in adjustments"
                    :key="a.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <a class="underline" :href="`/app/finance/concessions/${a.feeConcessionId}`">{{
                        a.category
                    }}</a>
                    <span
                        class="font-mono"
                        :class="{ 'text-slate-400 line-through': a.cancelledAt }"
                    >
                        {{ formatMoney(a.amount, a.currency) }}
                    </span>
                </li>
            </ul>
        </section>

        <section
            v-if="(payments && payments.length > 0) || otherPaymentsAmount"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Payments on this charge</h2>
            <p class="mt-1 text-sm text-slate-500">
                What each payment has on this charge now, after any corrections. Open a payment to
                move, reverse or refund it.
            </p>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                <li
                    v-for="p in payments"
                    :key="p.paymentId"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <a class="underline" :href="`/app/finance/payments/${p.paymentId}`"
                        >View payment</a
                    >
                    <span class="font-mono">{{ formatMoney(p.amount, charge.currency) }}</span>
                </li>
                <li
                    v-if="otherPaymentsAmount"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span class="text-slate-600"
                        >Payments recorded across campuses (whole-school finance can open
                        them)</span
                    >
                    <span class="font-mono">{{
                        formatMoney(otherPaymentsAmount, charge.currency)
                    }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="canRequestConcession && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Concession, scholarship or waiver</h2>
            <p class="mt-1 text-sm text-slate-500">
                Request a fixed reduction of this charge. Another person must approve it; the charge
                amount itself never changes.
            </p>
            <a
                class="mt-3 inline-block rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                :href="`/app/finance/concessions/create?charge_id=${charge.id}`"
                >Request concession</a
            >
        </section>

        <section
            v-if="canRecordPayment && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Payment received?</h2>
            <p class="mt-1 text-sm text-slate-500">
                Record cash, bank transfer or cheque money already received against this charge.
            </p>
            <a
                class="mt-3 inline-block rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                :href="`/app/finance/payments/record?student_id=${charge.studentId}&charge_id=${charge.id}`"
                >Record offline payment</a
            >
        </section>

        <section
            v-if="canManage && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Cancel this charge</h2>
            <p class="mt-1 text-sm text-slate-500">
                Cancelling records a reversing journal entry against the original recognition entry.
                The charge itself is kept, marked cancelled -- never deleted or edited. A charge
                with payments still standing on it, or with a live concession adjustment, cannot be
                cancelled; move the payments to the right charge from each payment's page first.
            </p>
            <p v-if="payments && payments.length > 0" class="mt-3 text-sm text-amber-800">
                Payments still stand on this charge, so it cannot be cancelled yet. Open each
                payment above and move the money to the right charge (or reverse or refund it), then
                cancel this charge.
            </p>
            <template v-else>
                <p class="mt-2 text-sm text-slate-500">
                    Cancel only a charge that should not stand. To excuse a valid charge, request a
                    concession instead.
                </p>
                <ChargeEndForm
                    id="cancel-charge"
                    :url="`/app/finance/charges/${charge.id}/cancel`"
                    :reasons="cancelReasons"
                    :has-mfa-factor="hasMfaFactor"
                    action="Cancelling a charge"
                    submit-label="Cancel charge"
                    confirmation="Cancel this charge? This creates a reversing journal entry and preserves the original charge -- it is not deleted."
                />
            </template>
        </section>
    </main>
</template>
