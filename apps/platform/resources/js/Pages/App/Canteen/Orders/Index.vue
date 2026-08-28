<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

// Highly Sensitive projection discipline (CanteenOrderController's own
// docblock): this summary row shape intentionally has NO unitPrice/
// lineTotal/totalAmount fields -- money only ever appears on the
// Show/detail page. Never widen this interface to "just in case" --
// see this phase's report for the test that pins this at the HTTP
// boundary.
interface OrderRow {
    id: string;
    status: 'pending' | 'fulfilled' | 'cancelled';
    studentId: string;
    outletId: string;
    placedAt: string;
    fulfilledAt: string | null;
    cancelledAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    orders: {
        data: OrderRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        status: string;
    };
    canManage: boolean;
}

const props = defineProps<Props>();

const status = ref(props.filters.status);

function applyFilter(): void {
    router.get(
        '/app/canteen-orders',
        { status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function fulfill(order: OrderRow): void {
    router.post(`/app/canteen-orders/${order.id}/fulfill`, {}, { preserveScroll: true });
}

function cancel(order: OrderRow): void {
    if (!window.confirm('Cancel this order?')) return;
    router.post(`/app/canteen-orders/${order.id}/cancel`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Canteen orders</h1>
                <p class="mt-1 text-sm text-slate-500">Pending queue and Order history.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/canteen-orders/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Place order
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-status">Status</label>
            <select
                id="filter-status"
                v-model="status"
                class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                @change="applyFilter"
            >
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="fulfilled">Fulfilled</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        <EmptyState
            v-if="orders.data.length === 0"
            class="mt-6"
            title="No Orders yet"
            description="Placed Canteen Orders will appear here."
        >
            <template v-if="canManage" #action>
                <a
                    href="/app/canteen-orders/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Place order
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Placed</th>
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Outlet</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="order in orders.data" :key="order.id">
                        <td class="py-3 text-slate-600">
                            {{ new Date(order.placedAt).toLocaleString() }}
                        </td>
                        <td class="py-3">
                            <a class="underline" :href="`/app/canteen-orders/${order.id}`">{{
                                order.studentId
                            }}</a>
                        </td>
                        <td class="py-3">{{ order.outletId }}</td>
                        <td class="py-3"><StatusBadge :status="order.status" /></td>
                        <td class="py-3 text-right">
                            <div v-if="canManage && order.status === 'pending'" class="space-x-3">
                                <button
                                    type="button"
                                    class="text-sm underline"
                                    @click="fulfill(order)"
                                >
                                    Fulfill
                                </button>
                                <button
                                    type="button"
                                    class="text-sm text-red-600 underline"
                                    @click="cancel(order)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="orders.links" />
        </template>
    </main>
</template>
