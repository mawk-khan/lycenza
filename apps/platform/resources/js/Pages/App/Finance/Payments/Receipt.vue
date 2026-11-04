<script setup lang="ts">
import { formatMoney } from '../../../../money';

interface ReceiptLine {
    chargeId: string;
    amount: string;
    description: string | null;
    feeHeadName: string | null;
    billingPeriodKey: string | null;
    billingPeriodLabel: string | null;
    academicYearName: string | null;
}

interface Props {
    receipt: {
        title: string;
        schoolName: string;
        receiptNumber: string;
        seriesKey: string;
        issuedOn: string;
        paymentId: string;
        receivedOn: string;
        methodLabel: string | null;
        reference: string | null;
        amount: string;
        currency: string;
        students: Array<{ name: string; studentNumber: string | null }>;
        lines: ReceiptLine[];
        corrections: Array<{
            kind: 'reallocation' | 'reversal' | 'refund';
            amount: string;
            on: string;
        }>;
    };
}

const props = defineProps<Props>();

const reversal = props.receipt.corrections.find((c) => c.kind === 'reversal');
const correctionLabel = { reallocation: 'Reallocated', reversal: 'Reversed', refund: 'Refunded' };

function print(): void {
    window.print();
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink print:p-0">
        <div class="flex items-center justify-between print:hidden">
            <a class="text-sm underline" :href="`/app/finance/payments/${receipt.paymentId}`"
                >← Payment</a
            >
            <button type="button" class="btn btn-primary" @click="print">Print</button>
        </div>

        <p
            v-if="reversal"
            role="note"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm font-medium text-ink"
        >
            REVERSED on {{ reversal.on }} -- this payment no longer stands. The receipt number is
            kept for the record.
        </p>

        <article class="mt-6 rounded-xs border border-control-line p-6 print:mt-0 print:border-0">
            <header class="flex items-start justify-between gap-4 border-b border-line pb-4">
                <div>
                    <p class="text-lg font-semibold">{{ receipt.schoolName }}</p>
                    <h1 class="mt-1 text-xl font-semibold">{{ receipt.title }}</h1>
                    <p class="text-xs text-ink-muted">Acknowledgement of payment received</p>
                </div>
                <dl class="text-right text-sm">
                    <dt class="text-ink-muted">Receipt no.</dt>
                    <dd class="font-mono font-medium">{{ receipt.receiptNumber }}</dd>
                    <dt class="mt-1 text-ink-muted">Issued</dt>
                    <dd>{{ receipt.issuedOn }}</dd>
                </dl>
            </header>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div>
                    <dt class="text-ink-muted">Received from (Student)</dt>
                    <dd v-for="s in receipt.students" :key="s.name + s.studentNumber">
                        {{ s.name }}
                        <span v-if="s.studentNumber" class="text-ink-muted"
                            >({{ s.studentNumber }})</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Payment received on</dt>
                    <dd>{{ receipt.receivedOn }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Method</dt>
                    <dd>{{ receipt.methodLabel ?? '—' }}</dd>
                </div>
                <div v-if="receipt.reference">
                    <dt class="text-ink-muted">Reference</dt>
                    <dd>{{ receipt.reference }}</dd>
                </div>
            </dl>

            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Applied to</th>
                        <th scope="col" class="py-2 font-medium">Period</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="l in receipt.lines" :key="l.chargeId">
                        <td class="py-2">
                            {{ l.feeHeadName ?? l.description ?? 'Charge' }}
                            <span
                                v-if="l.feeHeadName && l.description"
                                class="block text-xs text-ink-muted"
                                >{{ l.description }}</span
                            >
                        </td>
                        <td class="py-2">
                            {{ l.billingPeriodLabel ?? '—' }}
                            <span v-if="l.academicYearName" class="block text-xs text-ink-muted">{{
                                l.academicYearName
                            }}</span>
                        </td>
                        <td class="py-2 text-right font-mono">
                            {{ formatMoney(l.amount, receipt.currency) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-control-line font-medium">
                        <td class="py-2" colspan="2">Total received</td>
                        <td class="py-2 text-right font-mono">
                            {{ formatMoney(receipt.amount, receipt.currency) }}
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div
                v-if="receipt.corrections.length > 0"
                class="mt-4 border-t border-line pt-3 text-sm"
            >
                <p class="font-medium">Later corrections</p>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="(c, i) in receipt.corrections"
                        :key="i"
                        class="flex justify-between gap-2"
                    >
                        <span>{{ correctionLabel[c.kind] }} on {{ c.on }}</span>
                        <span class="font-mono">{{ formatMoney(c.amount, receipt.currency) }}</span>
                    </li>
                </ul>
            </div>

            <p class="mt-6 text-xs text-ink-muted">
                This is an acknowledgement that the payment above was received. It is not a tax
                invoice.
            </p>
        </article>
    </main>
</template>
