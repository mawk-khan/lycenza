<script setup lang="ts">
// ORG.9C-3 (ADR 0076 §71), INV-016 / INV-023: every stock movement in the
// reader's scope, newest first, narrowed by Campus, Location, Item, type and
// School-local dates. Issues show their Department, write-offs their reason;
// no person is ever recorded on a movement.
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { formatDateTime } from '../../../../datetime';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

type MovementType = 'receipt' | 'transfer' | 'issue' | 'write_off';

interface Row {
    id: string;
    occurredAt: string;
    movementType: MovementType;
    itemCode: string;
    itemName: string;
    unitOfMeasure: string;
    quantity: string;
    fromLocation: string | null;
    toLocation: string | null;
    recipientDepartment: string | null;
    writeOffReason: string | null;
}

interface Filters {
    campus_id: string | null;
    location_id: string | null;
    item: string | null;
    movement_type: MovementType | null;
    from: string | null;
    to: string | null;
}

const props = defineProps<{
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: Filters;
    options: {
        campuses: { id: string; name: string }[];
        locations: { id: string; code: string; name: string }[];
    };
    writeOffReasons: Record<string, string>;
}>();

const typeLabels: Record<MovementType, string> = {
    receipt: 'Receipt',
    transfer: 'Transfer',
    issue: 'Issue',
    write_off: 'Write-off',
};

const form = reactive({ ...props.filters });

function apply(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== null && v !== ''),
    );
    router.get('/app/inventory-stock/register', query, { replace: true });
}

function detail(row: Row): string {
    if (row.movementType === 'write_off' && row.writeOffReason !== null) {
        return props.writeOffReasons[row.writeOffReason] ?? row.writeOffReason;
    }
    if (row.movementType === 'issue' && row.recipientDepartment !== null) {
        return `To ${row.recipientDepartment}`;
    }
    return '—';
}
</script>

<template>
    <Head title="Stock register" />
    <main class="mx-auto max-w-6xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/inventory-stock">← Inventory stock</a>
        <h1 class="mt-2 text-xl font-semibold">Stock register</h1>
        <p class="mt-1 text-sm text-slate-500">
            Every receipt, transfer, issue and write-off at the locations you can see, newest first.
            Dates are the school's local dates.
        </p>

        <form class="mt-6 grid gap-3 sm:grid-cols-3" @submit.prevent="apply">
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
            <label class="text-sm text-slate-600">
                Type
                <select
                    v-model="form.movement_type"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option :value="null">All</option>
                    <option v-for="(label, value) in typeLabels" :key="value" :value="value">
                        {{ label }}
                    </option>
                </select>
            </label>
            <label class="text-sm text-slate-600">
                From
                <input
                    v-model="form.from"
                    type="date"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
            </label>
            <label class="text-sm text-slate-600">
                To
                <input
                    v-model="form.to"
                    type="date"
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

        <p class="mt-6 text-sm text-slate-600">{{ rows.total }} movement(s) match.</p>

        <EmptyState
            v-if="rows.data.length === 0"
            class="mt-3"
            title="No movements"
            description="Nothing matches these filters at the locations you can see."
        />
        <template v-else>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">When</th>
                            <th scope="col" class="py-2 font-medium">Type</th>
                            <th scope="col" class="py-2 font-medium">Item</th>
                            <th scope="col" class="py-2 font-medium">From</th>
                            <th scope="col" class="py-2 font-medium">To</th>
                            <th scope="col" class="py-2 font-medium">Quantity</th>
                            <th scope="col" class="py-2 font-medium">Recipient / reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in rows.data" :key="row.id">
                            <td class="py-3 text-slate-600">
                                {{ formatDateTime(row.occurredAt) }}
                            </td>
                            <td class="py-3">{{ typeLabels[row.movementType] }}</td>
                            <td class="py-3 font-medium">
                                {{ row.itemCode }} — {{ row.itemName }}
                            </td>
                            <td class="py-3">{{ row.fromLocation ?? '—' }}</td>
                            <td class="py-3">{{ row.toLocation ?? '—' }}</td>
                            <td class="py-3">{{ row.quantity }} {{ row.unitOfMeasure }}</td>
                            <td class="py-3 text-slate-600">{{ detail(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" />
        </template>
    </main>
</template>
