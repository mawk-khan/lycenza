<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface PaymentRow {
    id: string;
    provider: string;
    amount: string;
    currency: string;
    settledAt: string;
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
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>

        <h1 class="mt-2 text-xl font-semibold">Payments</h1>
        <p class="mt-1 text-sm text-slate-500">
            Read-only Payment records for this School. Settlement and allocation happen through the
            provider integration, not from this screen.
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
                    : 'No Payments have been settled for this School yet.'
            "
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Settled</th>
                        <th scope="col" class="py-2 font-medium">Provider</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="payment in payments.data" :key="payment.id">
                        <td class="py-3 text-slate-600">{{ payment.settledAt }}</td>
                        <td class="py-3">{{ payment.provider }}</td>
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
                        >{{ payment.provider }}</a
                    >
                    <p class="mt-1 text-sm text-slate-500">{{ payment.settledAt }}</p>
                    <p class="mt-1 font-mono text-sm">
                        {{ formatMoney(payment.amount, payment.currency) }}
                    </p>
                </li>
            </ul>

            <Pagination :links="payments.links" />
        </template>
    </main>
</template>
