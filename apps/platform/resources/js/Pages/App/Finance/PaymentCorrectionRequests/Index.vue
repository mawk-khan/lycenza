<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

type CorrectionKind = 'reallocation' | 'reversal' | 'refund';

interface RequestRow {
    id: string;
    paymentId: string;
    kind: CorrectionKind;
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
    amount: string | null;
    paymentAmount: string | null;
    paymentCurrency: string | null;
    requestedByName: string | null;
    requestedOn: string;
    decidedByName: string | null;
    decidedOn: string | null;
    isMine: boolean;
    canDecide: boolean;
    canWithdraw: boolean;
}

interface Props {
    requests: {
        data: RequestRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { status: string };
}

defineProps<Props>();

const statuses = ['pending', 'approved', 'rejected', 'withdrawn', 'all'];

const kindLabel: Record<CorrectionKind, string> = {
    reallocation: 'Move to another charge',
    reversal: 'Reverse',
    refund: 'Refund',
};

function filter(status: string): void {
    router.get('/app/finance/payment-correction-requests', { status }, { preserveState: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Payment corrections</h1>
        <p class="mt-1 text-sm text-slate-500">
            Reallocations, reversals and refunds of recorded payments. A correction is requested on
            the payment's page and recorded only when someone other than its requester approves it
            there.
        </p>

        <div class="mt-6 flex flex-wrap items-center gap-2 text-sm">
            <button
                v-for="s in statuses"
                :key="s"
                type="button"
                class="rounded-full border px-3 py-1 capitalize"
                :class="
                    filters.status === s
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-300'
                "
                @click="filter(s)"
            >
                {{ s === 'pending' ? 'Awaiting approval' : s }}
            </button>
        </div>

        <EmptyState
            v-if="requests.data.length === 0"
            class="mt-6"
            title="No correction requests here"
            description="Requests appear here; pending ones wait for someone else to decide them."
        />

        <div v-else class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Payment</th>
                        <th scope="col" class="py-2 font-medium">Correction</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 pl-4 font-medium">Requested</th>
                        <th scope="col" class="py-2 pl-4 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in requests.data" :key="r.id">
                        <td class="py-3">
                            <a class="underline" :href="`/app/finance/payments/${r.paymentId}`">{{
                                r.paymentAmount && r.paymentCurrency
                                    ? formatMoney(r.paymentAmount, r.paymentCurrency)
                                    : 'Payment'
                            }}</a>
                        </td>
                        <td class="py-3">{{ kindLabel[r.kind] }}</td>
                        <td class="py-3 text-right font-mono">
                            {{
                                r.amount && r.paymentCurrency
                                    ? formatMoney(r.amount, r.paymentCurrency)
                                    : 'Everything on it'
                            }}
                        </td>
                        <td class="py-3 pl-4">
                            {{ r.isMine ? 'You' : (r.requestedByName ?? 'unknown') }},
                            {{ r.requestedOn }}
                        </td>
                        <td class="py-3 pl-4">
                            <span class="capitalize">{{ r.status }}</span>
                            <template v-if="r.decidedOn">
                                <template v-if="r.decidedByName"> by {{ r.decidedByName }}</template
                                >, {{ r.decidedOn }}</template
                            >
                            <a
                                v-if="r.canDecide || r.canWithdraw"
                                class="ml-2 underline"
                                :href="`/app/finance/payments/${r.paymentId}`"
                                >{{ r.canDecide ? 'Decide' : 'Withdraw' }}</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination
            class="mt-4"
            :links="requests.links"
            :from="requests.from"
            :to="requests.to"
            :total="requests.total"
        />
    </main>
</template>
