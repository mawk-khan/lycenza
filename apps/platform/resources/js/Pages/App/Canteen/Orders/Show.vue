<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { formatMoney } from '../../../../money';

interface OrderLine {
    id: string;
    canteenItemId: string;
    quantity: number;
    unitPrice: string;
    lineTotal: string;
}

interface OrderDetail {
    id: string;
    status: 'pending' | 'fulfilled' | 'cancelled';
    studentId: string;
    outletId: string;
    placedAt: string;
    fulfilledAt: string | null;
    cancelledAt: string | null;
    totalAmount: string;
    currency: string;
    chargeId: string | null;
    lines: OrderLine[];
}

interface Props {
    order: OrderDetail;
    canManage: boolean;
}

const props = defineProps<Props>();

const fulfillError = ref('');
const cancelError = ref('');
const fulfilling = ref(false);
const cancelling = ref(false);

function fulfill(): void {
    fulfilling.value = true;
    fulfillError.value = '';
    router.post(
        `/app/canteen-orders/${props.order.id}/fulfill`,
        {},
        {
            onError: (errors) => {
                fulfillError.value = errors.fulfillment ?? 'This Order could not be fulfilled.';
            },
            onFinish: () => (fulfilling.value = false),
        },
    );
}

function cancel(): void {
    if (!window.confirm('Cancel this order?')) return;
    cancelling.value = true;
    cancelError.value = '';
    router.post(
        `/app/canteen-orders/${props.order.id}/cancel`,
        {},
        {
            onError: (errors) => {
                cancelError.value = errors.cancellation ?? 'This Order could not be cancelled.';
            },
            onFinish: () => (cancelling.value = false),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/canteen-orders">← Canteen orders</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Order {{ order.id }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Student {{ order.studentId }} · Outlet {{ order.outletId }}
                </p>
            </div>
            <StatusBadge :status="order.status" />
        </div>

        <p
            v-if="fulfillError"
            class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ fulfillError }}
        </p>
        <p
            v-if="cancelError"
            class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ cancelError }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Total</dt>
                <dd class="font-mono">{{ formatMoney(order.totalAmount, order.currency) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Placed</dt>
                <dd>{{ new Date(order.placedAt).toLocaleString() }}</dd>
            </div>
            <div v-if="order.fulfilledAt">
                <dt class="text-slate-500">Fulfilled</dt>
                <dd>{{ new Date(order.fulfilledAt).toLocaleString() }}</dd>
            </div>
            <div v-if="order.cancelledAt">
                <dt class="text-slate-500">Cancelled</dt>
                <dd>{{ new Date(order.cancelledAt).toLocaleString() }}</dd>
            </div>
            <div v-if="order.chargeId">
                <dt class="text-slate-500">Charge</dt>
                <dd>
                    <a class="underline" :href="`/app/finance/charges/${order.chargeId}`"
                        >View charge</a
                    >
                </dd>
            </div>
        </dl>

        <section class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">Lines</h2>
            <table class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Item</th>
                        <th scope="col" class="py-2 font-medium">Quantity</th>
                        <th scope="col" class="py-2 text-right font-medium">Unit price</th>
                        <th scope="col" class="py-2 text-right font-medium">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="line in order.lines" :key="line.id">
                        <td class="py-3">{{ line.canteenItemId }}</td>
                        <td class="py-3">{{ line.quantity }}</td>
                        <td class="py-3 text-right font-mono">
                            {{ formatMoney(line.unitPrice, order.currency) }}
                        </td>
                        <td class="py-3 text-right font-mono">
                            {{ formatMoney(line.lineTotal, order.currency) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="canManage && order.status === 'pending'" class="mt-8 flex gap-3">
            <button
                type="button"
                :disabled="fulfilling"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                @click="fulfill"
            >
                {{ fulfilling ? 'Fulfilling…' : 'Fulfill order' }}
            </button>
            <button
                type="button"
                :disabled="cancelling"
                class="rounded border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                @click="cancel"
            >
                {{ cancelling ? 'Cancelling…' : 'Cancel order' }}
            </button>
        </section>
    </main>
</template>
