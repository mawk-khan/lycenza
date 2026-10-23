<script setup lang="ts">
// ORG.9C-3 (ADR 0076 §71), INV-020: for one School-local month, each Item's
// opening quantity, what came in and went out, and its closing quantity at the
// selected locations -- computed from the movement history, never stored.
// A transfer between two selected locations does not change the total; one
// crossing the selection counts at the selected end only.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface Row {
    itemId: string;
    itemCode: string;
    itemName: string;
    unitOfMeasure: string;
    opening: string;
    received: string;
    transferredIn: string;
    issued: string;
    writtenOff: string;
    transferredOut: string;
    closing: string;
}

const props = defineProps<{
    summary: {
        month: string;
        from: string;
        to: string;
        rows: {
            data: Row[];
            links: { url: string | null; label: string; active: boolean }[];
            total: number;
        };
    };
    filters: {
        month: string;
        campus_id: string | null;
        location_id: string | null;
        item: string | null;
    };
    options: {
        campuses: { id: string; name: string }[];
        locations: { id: string; code: string; name: string }[];
    };
}>();

const form = reactive({ ...props.filters });
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

const columns: { key: keyof Row; label: string }[] = [
    { key: 'opening', label: 'Opening' },
    { key: 'received', label: 'Received' },
    { key: 'transferredIn', label: 'In from other locations' },
    { key: 'issued', label: 'Issued' },
    { key: 'writtenOff', label: 'Written off' },
    { key: 'transferredOut', label: 'Out to other locations' },
    { key: 'closing', label: 'Closing' },
];

function apply(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== null && v !== ''),
    );
    router.get('/app/inventory-stock/monthly', query, { replace: true });
}
</script>

<template>
    <Head title="Stock by month" />
    <main class="mx-auto max-w-6xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/inventory-stock">← Inventory stock</a>
        <h1 class="mt-2 text-xl font-semibold">Stock by month</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ summary.from }} to {{ summary.to }} (school dates). Opening is the stock before the
            first day; closing is opening plus everything in and out during the period. Each row is
            in that item's own unit, so rows are not added together.
        </p>

        <form class="mt-6 grid gap-3 sm:grid-cols-4" @submit.prevent="apply">
            <label class="text-sm text-slate-600">
                Month
                <input
                    v-model="form.month"
                    type="month"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
                <span v-if="pageErrors.month" class="mt-1 block text-red-600">{{
                    pageErrors.month
                }}</span>
            </label>
            <label class="text-sm text-slate-600">
                Campus
                <select
                    v-model="form.campus_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option :value="null">All</option>
                    <option v-for="c in options.campuses" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </label>
            <label class="text-sm text-slate-600">
                Location
                <select
                    v-model="form.location_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option :value="null">All</option>
                    <option v-for="l in options.locations" :key="l.id" :value="l.id">
                        {{ l.code }} — {{ l.name }}
                    </option>
                </select>
            </label>
            <label class="text-sm text-slate-600">
                Item (code or name)
                <input
                    v-model="form.item"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
            </label>
            <div>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white"
                >
                    Show
                </button>
            </div>
        </form>

        <EmptyState
            v-if="summary.rows.data.length === 0"
            class="mt-6"
            title="No stock in this period"
            description="No item had stock or movements at the selected locations."
        />
        <template v-else>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Item</th>
                            <th
                                v-for="c in columns"
                                :key="c.key"
                                scope="col"
                                class="py-2 text-right font-medium"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in summary.rows.data" :key="row.itemId">
                            <td class="py-3 font-medium">
                                {{ row.itemCode }} — {{ row.itemName }}
                                <span class="text-xs text-slate-500"
                                    >({{ row.unitOfMeasure }})</span
                                >
                            </td>
                            <td
                                v-for="c in columns"
                                :key="c.key"
                                class="py-3 text-right tabular-nums"
                            >
                                {{ row[c.key] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="summary.rows.links" />
        </template>
    </main>
</template>
