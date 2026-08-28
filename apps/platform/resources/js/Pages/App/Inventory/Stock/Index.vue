<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface BalanceRow {
    id: string;
    quantityOnHand: string;
    itemCode: string;
    itemName: string;
    unitOfMeasure: string;
    locationCode: string;
    locationName: string;
}

interface MovementRow {
    id: string;
    movementType: 'receipt' | 'issue' | 'transfer';
    quantity: string;
    occurredAt: string;
    itemCode: string;
    fromLocationCode: string | null;
    toLocationCode: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    balances: {
        data: BalanceRow[];
        links: PageLink[];
        total: number;
    };
    movements: {
        data: MovementRow[];
        links: PageLink[];
        total: number;
    };
    canManage: boolean;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Inventory stock</h1>
                <p class="mt-1 text-sm text-slate-500">Current balances and movement history.</p>
            </div>
            <div v-if="canManage" class="flex shrink-0 gap-2">
                <a
                    href="/app/inventory-stock/receive"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Receive
                </a>
                <a
                    href="/app/inventory-stock/issue"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Issue
                </a>
                <a
                    href="/app/inventory-stock/transfer"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Transfer
                </a>
            </div>
        </div>

        <section class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">Current balances</h2>

            <EmptyState
                v-if="balances.data.length === 0"
                class="mt-3"
                title="No stock balances yet"
                description="Receive stock into a Location to see it here."
            />

            <template v-else>
                <table class="mt-3 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Item</th>
                            <th scope="col" class="py-2 font-medium">Location</th>
                            <th scope="col" class="py-2 font-medium">Quantity on hand</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="balance in balances.data" :key="balance.id">
                            <td class="py-3 font-medium">
                                {{ balance.itemCode }} — {{ balance.itemName }}
                            </td>
                            <td class="py-3">
                                {{ balance.locationCode }} — {{ balance.locationName }}
                            </td>
                            <td class="py-3">
                                {{ balance.quantityOnHand }} {{ balance.unitOfMeasure }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <Pagination :links="balances.links" />
            </template>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-slate-700">Movement history</h2>

            <EmptyState
                v-if="movements.data.length === 0"
                class="mt-3"
                title="No stock movements yet"
                description="Receipts, issues, and transfers will appear here."
            />

            <template v-else>
                <table class="mt-3 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">When</th>
                            <th scope="col" class="py-2 font-medium">Type</th>
                            <th scope="col" class="py-2 font-medium">Item</th>
                            <th scope="col" class="py-2 font-medium">From</th>
                            <th scope="col" class="py-2 font-medium">To</th>
                            <th scope="col" class="py-2 font-medium">Quantity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="movement in movements.data" :key="movement.id">
                            <td class="py-3 text-slate-600">
                                {{ new Date(movement.occurredAt).toLocaleString() }}
                            </td>
                            <td class="py-3 capitalize">{{ movement.movementType }}</td>
                            <td class="py-3 font-medium">{{ movement.itemCode }}</td>
                            <td class="py-3">{{ movement.fromLocationCode ?? '—' }}</td>
                            <td class="py-3">{{ movement.toLocationCode ?? '—' }}</td>
                            <td class="py-3">{{ movement.quantity }}</td>
                        </tr>
                    </tbody>
                </table>

                <Pagination :links="movements.links" />
            </template>
        </section>
    </main>
</template>
