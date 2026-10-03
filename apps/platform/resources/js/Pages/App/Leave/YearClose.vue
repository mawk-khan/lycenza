<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    blockerLabels,
    employeeName,
    typeName,
    units,
    type EmployeeLabel,
    type LeaveTypeRow,
} from '../../../leave';
import { useLeaveError } from '../../../leave';

/**
 * HRX.2 — closing a leave year.
 *
 * A year can be closed once it has ended, after the previous year is closed,
 * once the next year is open and when no submitted request remains in it.
 * Each balance carries forward up to its policy's cap; the rest lapses. The
 * close happens once and is never rerun: a request cancelled afterwards is
 * reconciled with new entries under the same policy terms.
 */
interface YearRow {
    id: string;
    label: string;
    startsOn: string;
    endsOn: string;
    isTransition: boolean;
}

interface CloseItem {
    employmentRecordId: string;
    leaveTypeId: string;
    closingUnits: number;
    carriedUnits: number;
    lapsedUnits: number;
    carriedExpiresOn: string | null;
}

interface Props {
    years: YearRow[];
    closes: {
        id: string;
        leaveYearId: string;
        nextLeaveYearId: string;
        itemCount: number;
        executedAt: string;
    }[];
    leaveYearId: string;
    preview: { blockers: string[]; nextLeaveYearId: string | null; items: CloseItem[] } | null;
    close: {
        id: string;
        items: CloseItem[];
        reconciliations: {
            id: string;
            leaveRequestId: string;
            units: number;
            carriedDelta: number;
            lapsedDelta: number;
        }[];
    } | null;
    employees: Record<string, EmployeeLabel>;
    types: LeaveTypeRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const leaveYearId = ref(props.leaveYearId);
watch(leaveYearId, () => {
    router.get(
        '/app/leave/year-close',
        { leave_year_id: leaveYearId.value || undefined },
        { preserveState: false, replace: true },
    );
});

const form = useForm({ leave_year_id: '' });

function execute(): void {
    form.leave_year_id = leaveYearId.value;
    form.post('/app/leave/year-close', { preserveScroll: true });
}

function yearLabel(id: string): string {
    return props.years.find((y) => y.id === id)?.label ?? '—';
}

function isClosed(id: string): boolean {
    return props.closes.some((c) => c.leaveYearId === id);
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave year close</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Carried leave expiry dates are recorded for information; lapsing carried leave inside
            the next year is not applied automatically yet.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="close-year">Leave year</label>
            <select
                id="close-year"
                v-model="leaveYearId"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option value="">Select a leave year</option>
                <option v-for="y in years" :key="y.id" :value="y.id">
                    {{ y.label }}{{ y.isTransition ? ' (transition)' : ''
                    }}{{ isClosed(y.id) ? ' — closed' : '' }}
                </option>
            </select>
        </div>

        <section
            v-if="canManage && preview !== null"
            class="mt-6 rounded border border-slate-300 p-4"
        >
            <h2 class="text-sm font-semibold">Preview</h2>
            <ul v-if="preview.blockers.length > 0" class="mt-2 text-sm text-red-700">
                <li v-for="b in preview.blockers" :key="b">{{ blockerLabels[b] ?? b }}</li>
            </ul>
            <table v-if="preview.items.length > 0" class="mt-3 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Employee</th>
                        <th class="py-2">Leave type</th>
                        <th class="py-2">Closing</th>
                        <th class="py-2">Carried</th>
                        <th class="py-2">Lapses</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in preview.items"
                        :key="item.employmentRecordId + item.leaveTypeId"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ employeeName(employees, item.employmentRecordId) }}</td>
                        <td class="py-2">{{ typeName(types, item.leaveTypeId) }}</td>
                        <td class="py-2">{{ units(item.closingUnits) }}</td>
                        <td class="py-2">{{ units(item.carriedUnits) }}</td>
                        <td class="py-2">{{ units(item.lapsedUnits) }}</td>
                    </tr>
                </tbody>
            </table>
            <button
                v-if="preview.blockers.length === 0"
                type="button"
                class="mt-4 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                :disabled="form.processing"
                @click="execute"
            >
                Close {{ yearLabel(leaveYearId) }}
            </button>
        </section>

        <h2 class="mt-8 text-sm font-semibold">Closed years</h2>
        <ul class="mt-2 text-sm">
            <li v-for="c in closes" :key="c.id">
                <a class="underline" :href="`/app/leave/year-close?close_id=${c.id}`">{{
                    yearLabel(c.leaveYearId)
                }}</a>
                → {{ yearLabel(c.nextLeaveYearId) }} · {{ c.itemCount }} balances ·
                {{ c.executedAt }}
            </li>
            <li v-if="closes.length === 0" class="text-slate-500">
                No leave year has been closed.
            </li>
        </ul>

        <section v-if="close !== null" class="mt-6">
            <h3 class="text-sm font-semibold">Close details</h3>
            <table class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Employee</th>
                        <th class="py-2">Leave type</th>
                        <th class="py-2">Closing</th>
                        <th class="py-2">Carried</th>
                        <th class="py-2">Lapsed</th>
                        <th class="py-2">Carried expiry (recorded)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in close.items"
                        :key="item.employmentRecordId + item.leaveTypeId"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ employeeName(employees, item.employmentRecordId) }}</td>
                        <td class="py-2">{{ typeName(types, item.leaveTypeId) }}</td>
                        <td class="py-2">{{ item.closingUnits }}</td>
                        <td class="py-2">{{ item.carriedUnits }}</td>
                        <td class="py-2">{{ item.lapsedUnits }}</td>
                        <td class="py-2">{{ item.carriedExpiresOn ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <h3 class="mt-4 text-sm font-semibold">Reconciliations after the close</h3>
            <ul class="mt-2 text-sm">
                <li v-for="r in close.reconciliations" :key="r.id">
                    <a class="underline" :href="`/app/leave/requests/${r.leaveRequestId}`"
                        >Cancelled request</a
                    >: {{ units(r.units) }} returned — {{ r.carriedDelta }} carried,
                    {{ r.lapsedDelta }} lapsed
                </li>
                <li v-if="close.reconciliations.length === 0" class="text-slate-500">None.</li>
            </ul>
        </section>
    </main>
</template>
