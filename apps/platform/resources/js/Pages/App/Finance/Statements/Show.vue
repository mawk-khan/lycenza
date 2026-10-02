<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { formatMoney } from '../../../../money';

interface StatementPayment {
    paymentId: string;
    amount: string;
    receivedOn: string | null;
    method: string | null;
    receiptNumber: string | null;
}

interface StatementAdjustment {
    id: string;
    feeConcessionId: string;
    category: string;
    amount: string;
    cancelledAt: string | null;
}

interface StatementLine {
    chargeId: string;
    academicYearName: string | null;
    description: string;
    feeHeadName: string | null;
    billingPeriodLabel: string | null;
    dueDate: string | null;
    cancelledAt: string | null;
    amount: string;
    adjustedTotal: string;
    paidTotal: string;
    outstanding: string;
    currency: string;
    adjustments: StatementAdjustment[];
    payments: StatementPayment[];
}

interface Props {
    student: { id: string; name: string; studentNumber: string | null };
    statement: {
        currency: string;
        lines: StatementLine[];
        totals: { charged: string; adjusted: string; paid: string; outstanding: string };
        detailExpiredThrough: string | null;
    };
    academicYears: Array<{ id: string; name: string }>;
    filters: { academic_year_id: string };
}

const props = defineProps<Props>();

function filterYear(id: string): void {
    router.get(`/app/finance/fee-statements/${props.student.id}`, {
        academic_year_id: id || undefined,
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/fee-statements">← Fee statements</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Fee statement</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ student.name }}
                    <span v-if="student.studentNumber">({{ student.studentNumber }})</span>
                </p>
            </div>
            <select
                class="rounded border border-slate-300 px-2 py-1 text-sm"
                aria-label="Academic year"
                :value="filters.academic_year_id"
                @change="filterYear(($event.target as HTMLSelectElement).value)"
            >
                <option value="">All academic years</option>
                <option v-for="y in academicYears" :key="y.id" :value="y.id">{{ y.name }}</option>
            </select>
        </div>

        <p
            v-if="statement.detailExpiredThrough"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600"
        >
            Detailed records of financial years up to {{ statement.detailExpiredThrough }} have
            expired under the retention policy. Settled charges from those years are no longer
            listed; they owe nothing, so the outstanding total is unaffected.
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Charged</dt>
                <dd class="font-mono">
                    {{ formatMoney(statement.totals.charged, statement.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Concessions</dt>
                <dd class="font-mono">
                    {{ formatMoney(statement.totals.adjusted, statement.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Paid</dt>
                <dd class="font-mono">
                    {{ formatMoney(statement.totals.paid, statement.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-900 p-3">
                <dt class="text-slate-500">Outstanding</dt>
                <dd class="font-mono font-semibold">
                    {{ formatMoney(statement.totals.outstanding, statement.currency) }}
                </dd>
            </div>
        </dl>

        <p v-if="statement.lines.length === 0" class="mt-6 text-sm text-slate-500">
            No charges for this Student{{
                filters.academic_year_id ? ' in this academic year' : ''
            }}.
        </p>

        <table v-else class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Charge</th>
                    <th scope="col" class="py-2 font-medium">Concessions and payments</th>
                    <th scope="col" class="py-2 text-right font-medium">Amount</th>
                    <th scope="col" class="py-2 text-right font-medium">Outstanding</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 align-top">
                <tr v-for="l in statement.lines" :key="l.chargeId">
                    <td class="py-3">
                        <a class="underline" :href="`/app/finance/charges/${l.chargeId}`">{{
                            l.feeHeadName ?? l.description
                        }}</a>
                        <span class="block text-xs text-slate-500">
                            {{ l.billingPeriodLabel ?? l.description }}
                            <template v-if="l.academicYearName">
                                · {{ l.academicYearName }}</template
                            >
                            <template v-if="l.dueDate"> · due {{ l.dueDate }}</template>
                        </span>
                        <span
                            v-if="l.cancelledAt"
                            class="mt-1 inline-block rounded-full bg-red-50 px-2 text-xs text-red-700"
                            >Cancelled</span
                        >
                    </td>
                    <td class="py-3 text-xs">
                        <p
                            v-for="a in l.adjustments"
                            :key="a.id"
                            :class="{ 'text-slate-400 line-through': a.cancelledAt }"
                        >
                            <span class="capitalize">{{ a.category }}</span>
                            −{{ formatMoney(a.amount, l.currency) }}
                        </p>
                        <p v-for="p in l.payments" :key="p.paymentId">
                            Paid {{ formatMoney(p.amount, l.currency) }} on {{ p.receivedOn }}
                            <a
                                v-if="p.receiptNumber"
                                class="font-mono underline"
                                :href="`/app/finance/payments/${p.paymentId}/receipt`"
                                >{{ p.receiptNumber }}</a
                            >
                            <span v-else class="text-slate-500">(no receipt yet)</span>
                        </p>
                    </td>
                    <td
                        class="py-3 text-right font-mono"
                        :class="{ 'text-slate-400 line-through': l.cancelledAt }"
                    >
                        {{ formatMoney(l.amount, l.currency) }}
                    </td>
                    <td class="py-3 text-right font-mono">
                        {{ formatMoney(l.outstanding, l.currency) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
