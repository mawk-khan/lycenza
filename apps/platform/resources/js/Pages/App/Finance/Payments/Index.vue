<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface PaymentRow {
    id: string;
    source: 'provider' | 'manual';
    provider: string | null;
    method: string | null;
    methodLabel: string | null;
    amount: string;
    currency: string;
    settledAt: string;
    occurredOn: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    payments: {
        data: PaymentRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        provider_payment_reference: string;
    };
    canRecord: boolean;
}

const props = defineProps<Props>();

const providerPaymentReference = ref(props.filters.provider_payment_reference);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/finance/payments',
        { provider_payment_reference: providerPaymentReference.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(providerPaymentReference, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

const hasFilters = !!providerPaymentReference.value;

function sourceLabel(payment: PaymentRow): string {
    return payment.source === 'manual'
        ? `Offline · ${payment.methodLabel ?? payment.method}`
        : `Provider · ${payment.provider}`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <h1 class="text-xl font-semibold">Payments</h1>
            <a
                v-if="canRecord"
                href="/app/finance/payments/record"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white"
                >Record offline payment</a
            >
        </div>
        <p class="mt-1 text-sm text-slate-500">
            Payment records for this School -- offline payments recorded here (cash, bank transfer,
            cheque) and any from a payment provider. Recorded payments cannot be edited or deleted.
        </p>

        <form class="mt-6 flex items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-reference"
                    >Provider reference</label
                >
                <input
                    id="filter-reference"
                    v-model="providerPaymentReference"
                    type="text"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
        </form>

        <EmptyState
            v-if="payments.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No payments match that reference' : 'No payments yet'"
            :description="
                hasFilters
                    ? 'Try a different provider reference.'
                    : 'No Payments have been recorded for this School yet.'
            "
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Received</th>
                        <th scope="col" class="py-2 font-medium">Source</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="payment in payments.data" :key="payment.id">
                        <td class="py-3 text-slate-600">{{ payment.occurredOn }}</td>
                        <td class="py-3">{{ sourceLabel(payment) }}</td>
                        <td class="py-3 text-right font-mono">
                            {{ formatMoney(payment.amount, payment.currency) }}
                        </td>
                        <td class="py-3 text-right">
                            <a
                                class="text-sm underline"
                                :href="`/app/finance/payments/${payment.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="payment in payments.data"
                    :key="payment.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a
                        class="font-medium underline"
                        :href="`/app/finance/payments/${payment.id}`"
                        >{{ sourceLabel(payment) }}</a
                    >
                    <p class="mt-1 text-sm text-slate-500">{{ payment.occurredOn }}</p>
                    <p class="mt-1 font-mono text-sm">
                        {{ formatMoney(payment.amount, payment.currency) }}
                    </p>
                </li>
            </ul>

            <Pagination :links="payments.links" />
        </template>
    </main>
</template>
