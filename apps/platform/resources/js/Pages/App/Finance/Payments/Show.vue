<script setup lang="ts">
import { formatMoney } from '../../../../money';

interface PaymentAllocation {
    chargeId: string;
    amount: string;
}

interface PaymentDetail {
    id: string;
    provider: string;
    providerPaymentReference: string;
    amount: string;
    currency: string;
    settlementLedgerAccountId: string;
    journalEntryId: string;
    settledAt: string;
    allocations: PaymentAllocation[];
}

interface Props {
    payment: PaymentDetail;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/payments">← Payments</a>

        <h1 class="mt-2 text-xl font-semibold">Payment</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ payment.provider }} · settled {{ payment.settledAt }}. Read-only -- Payments cannot
            be edited, settled, allocated, or refunded from this screen.
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Amount</dt>
                <dd class="font-mono">{{ formatMoney(payment.amount, payment.currency) }}</dd>
            </div>
            <div>
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
