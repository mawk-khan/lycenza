<script setup lang="ts">
import { formatMoney } from '../../../../money';

interface PaymentAllocation {
    chargeId: string;
    amount: string;
}

interface PaymentDetail {
    id: string;
    source: 'provider' | 'manual';
    provider: string | null;
    providerPaymentReference: string | null;
    method: string | null;
    methodLabel: string | null;
    manualReference: string | null;
    recordedByName: string | null;
    occurredOn: string;
    recordedAt: string;
    amount: string;
    currency: string;
    settlementLedgerAccountId: string;
    journalEntryId: string;
    settledAt: string;
    allocations: PaymentAllocation[];
}

interface Props {
    payment: PaymentDetail;
    recordedOutcome: 'recorded' | 'duplicate_replay' | null;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/payments">← Payments</a>

        <p
            v-if="recordedOutcome === 'recorded'"
            role="status"
            class="mt-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            Payment recorded. It is now part of this School's permanent Finance record.
        </p>
        <p
            v-else-if="recordedOutcome === 'duplicate_replay'"
            role="status"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
        >
            This payment was already recorded -- nothing new was created.
        </p>

        <h1 class="mt-2 text-xl font-semibold">
            {{ payment.source === 'manual' ? 'Offline payment' : 'Payment' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            <template v-if="payment.source === 'manual'">
                {{ payment.methodLabel }} · received {{ payment.occurredOn }}.
            </template>
            <template v-else> {{ payment.provider }} · settled {{ payment.occurredOn }}. </template>
            Payments cannot be edited, deleted or refunded.
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Amount</dt>
                <dd class="font-mono">{{ formatMoney(payment.amount, payment.currency) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Source</dt>
                <dd>
                    {{
                        payment.source === 'manual'
                            ? 'Recorded offline in Lycenza'
                            : 'Payment provider'
                    }}
                </dd>
            </div>
            <template v-if="payment.source === 'manual'">
                <div>
                    <dt class="text-slate-500">Method</dt>
                    <dd>{{ payment.methodLabel }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reference</dt>
                    <dd>{{ payment.manualReference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Received on</dt>
                    <dd>{{ payment.occurredOn }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Recorded</dt>
                    <dd>
                        {{ payment.recordedAt }}
                        <span v-if="payment.recordedByName" class="block text-slate-500"
                            >by {{ payment.recordedByName }}</span
                        >
                    </dd>
                </div>
            </template>
            <div v-else>
                <dt class="text-slate-500">Provider reference</dt>
                <dd>{{ payment.providerPaymentReference }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Settlement journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${payment.journalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
        </dl>

        <section class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Allocations</h2>
            <p class="mt-1 text-sm text-slate-500">
                How this Payment's amount was applied across Charges. Allocations are immutable
                facts, not editable here.
            </p>

            <table v-if="payment.allocations.length > 0" class="mt-4 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Charge</th>
                        <th scope="col" class="py-2 text-right font-medium">Allocated amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="allocation in payment.allocations" :key="allocation.chargeId">
                        <td class="py-3">
                            <a
                                class="underline"
                                :href="`/app/finance/charges/${allocation.chargeId}`"
                                >View charge</a
                            >
                        </td>
                        <td class="py-3 text-right font-mono">
                            {{ formatMoney(allocation.amount, payment.currency) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="mt-4 text-sm text-slate-500">No allocations recorded.</p>
        </section>
    </main>
</template>
