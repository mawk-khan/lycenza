<script setup lang="ts">
import { computed } from 'vue';
import {
    ledgerKindLabels,
    portionLabels,
    reasonLabels,
    requestStatusLabels,
    typeName,
    units,
    type EmployeeLabel,
    type LeaveTypeRow,
} from '../../../leave';

/**
 * HRX.2 — one leave request and its evidence.
 *
 * The chargeable days are the snapshot written at approval: they never change
 * when the calendar, a policy or the leave-year configuration changes later.
 * Decisions and ledger entries are append-only.
 */
interface Props {
    request: {
        id: string;
        employmentRecordId: string;
        leaveTypeId: string;
        startsOn: string;
        startPortion: string;
        endsOn: string;
        endPortion: string;
        reasonCode: string | null;
        submittedUnits: number;
        status: string;
        days: {
            date: string;
            portion: string;
            units: number;
            leaveYearId: string;
            leavePolicyId: string | null;
        }[];
        decisions: {
            id: string;
            decision: string;
            path: string;
            reasonCode: string | null;
            decidedAt: string | null;
        }[];
        ledgerEntries: {
            id: string;
            kind: string;
            units: number;
            leaveYearId: string;
            createdAt: string | null;
        }[];
    };
    employee: EmployeeLabel | null;
    types: LeaveTypeRow[];
    years: { id: string; label: string }[];
}

const props = defineProps<Props>();

const chargedUnits = computed(() => props.request.days.reduce((sum, d) => sum + d.units, 0));

function yearLabel(id: string): string {
    return props.years.find((y) => y.id === id)?.label ?? '—';
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave request</h1>
        <dl class="mt-4 grid grid-cols-1 gap-2 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">Employee</dt>
                <dd>{{ employee ? `${employee.fullName} (${employee.employeeNumber})` : '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Leave type</dt>
                <dd>{{ typeName(types, request.leaveTypeId) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Period</dt>
                <dd>
                    {{ request.startsOn }} ({{ portionLabels[request.startPortion] }}) →
                    {{ request.endsOn }} ({{ portionLabels[request.endPortion] }})
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd>{{ requestStatusLabels[request.status] }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Reason</dt>
                <dd>{{ request.reasonCode ? reasonLabels[request.reasonCode] : '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Units at submission</dt>
                <dd>{{ units(request.submittedUnits) }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 text-sm font-semibold">Chargeable days (fixed at approval)</h2>
        <p v-if="request.days.length === 0" class="mt-2 text-sm text-slate-500">
            Not approved: nothing has been charged.
        </p>
        <table v-else class="mt-2 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Date</th>
                    <th class="py-2">Portion</th>
                    <th class="py-2">Units</th>
                    <th class="py-2">Leave year</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in request.days" :key="day.date" class="border-b border-slate-100">
                    <td class="py-2">{{ day.date }}</td>
                    <td class="py-2">{{ portionLabels[day.portion] }}</td>
                    <td class="py-2">{{ day.units }}</td>
                    <td class="py-2">{{ yearLabel(day.leaveYearId) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="request.days.length > 0" class="mt-2 text-sm">
            Charged: {{ units(chargedUnits) }}
        </p>

        <h2 class="mt-8 text-sm font-semibold">Decisions</h2>
        <ul class="mt-2 text-sm">
            <li v-for="d in request.decisions" :key="d.id">
                {{ requestStatusLabels[d.decision] }} ·
                {{ d.path === 'manager' ? 'manager' : 'administrator' }}
                <template v-if="d.reasonCode">· {{ reasonLabels[d.reasonCode] }}</template>
                · {{ d.decidedAt }}
            </li>
            <li v-if="request.decisions.length === 0" class="text-slate-500">Not decided yet.</li>
        </ul>

        <h2 class="mt-8 text-sm font-semibold">Ledger effects</h2>
        <ul class="mt-2 text-sm">
            <li v-for="e in request.ledgerEntries" :key="e.id">
                {{ ledgerKindLabels[e.kind] }} · {{ units(e.units) }} ·
                {{ yearLabel(e.leaveYearId) }}
            </li>
            <li v-if="request.ledgerEntries.length === 0" class="text-slate-500">None.</li>
        </ul>
    </main>
</template>
