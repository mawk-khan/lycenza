<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * POR.3 — one child's fees, read-only: this academic year's charges and any
 * other year's charge still unpaid. Payments show only the amount applied to this
 * child, never a whole payment.
 */
interface PaymentRow {
    paymentId: string;
    settledOn: string | null;
    method: string | null;
    receiptNumber: string | null;
    appliedAmount: string;
}

interface Line {
    description: string;
    feeHeadName: string | null;
    billingPeriodLabel: string | null;
    dueDate: string | null;
    currentYear: boolean;
    status: 'outstanding' | 'settled' | 'cancelled';
    amount: string;
    adjustedTotal: string;
    paidTotal: string;
    outstanding: string;
    payments: PaymentRow[];
}

interface Props {
    schoolName: string;
    students: { id: string; name: string }[];
    statement: {
        student: { id: string; name: string };
        currency: string;
        lines: Line[];
        totals: { charged: string; adjusted: string; paid: string; outstanding: string };
    };
}

const props = defineProps<Props>();

const statusLabels: Record<Line['status'], string> = {
    outstanding: 'Due',
    settled: 'Paid',
    cancelled: 'Cancelled',
};

function paymentUrl(paymentId: string): string {
    return `/app/portal/fees/students/${props.statement.student.id}/payments/${paymentId}`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl px-6 py-10">
        <a class="text-sm underline" href="/app/portal/fees">Back</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">
            Fees · {{ statement.student.name }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ schoolName }} · this academic year, and fees of other years still due. Amounts in
            {{ statement.currency }}.
        </p>

        <nav
            v-if="students.length > 1"
            class="mt-4 flex flex-wrap gap-3 text-sm"
            aria-label="Your students"
        >
            <a
                v-for="student in students"
                :key="student.id"
                :class="student.id === statement.student.id ? 'font-semibold' : 'underline'"
                :href="`/app/portal/fees/students/${student.id}`"
                >{{ student.name }}</a
            >
        </nav>

        <dl class="mt-6 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-slate-500">Charged</dt>
                <dd class="font-medium">{{ statement.totals.charged }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Adjustments</dt>
                <dd class="font-medium">{{ statement.totals.adjusted }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Paid</dt>
                <dd class="font-medium">{{ statement.totals.paid }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Due</dt>
                <dd class="font-semibold">{{ statement.totals.outstanding }}</dd>
            </div>
        </dl>

        <EmptyState
            v-if="statement.lines.length === 0"
            class="mt-6"
            title="No fees"
            description="There are no fees for this academic year and nothing from another year is due."
        />

        <ul v-else class="mt-6 space-y-4">
            <li
                v-for="(line, index) in statement.lines"
                :key="index"
                class="rounded border border-slate-200 p-4 text-sm"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-slate-900">
                        {{ line.feeHeadName ?? line.description }}
                        <template v-if="line.billingPeriodLabel">
                            · {{ line.billingPeriodLabel }}</template
                        >
                    </span>
                    <span class="text-xs text-slate-500">
                        <template v-if="!line.currentYear">Other academic year · </template>
                        <template v-if="line.dueDate">Due {{ line.dueDate }} · </template>
                        {{ statusLabels[line.status] }}
                    </span>
                </div>
                <p class="mt-1 text-slate-600">
                    Amount {{ line.amount }} · Adjustments {{ line.adjustedTotal }} · Paid
                    {{ line.paidTotal }} · Due
                    {{ line.outstanding }}
                </p>
                <ul v-if="line.payments.length > 0" class="mt-2 space-y-1 text-xs text-slate-600">
                    <li v-for="payment in line.payments" :key="payment.paymentId">
                        <a class="underline" :href="paymentUrl(payment.paymentId)">{{
                            payment.settledOn
                        }}</a>
                        · {{ payment.appliedAmount }} applied to this fee
                        <template v-if="payment.receiptNumber">
                            · receipt {{ payment.receiptNumber }}</template
                        >
                    </li>
                </ul>
            </li>
        </ul>
    </main>
</template>
