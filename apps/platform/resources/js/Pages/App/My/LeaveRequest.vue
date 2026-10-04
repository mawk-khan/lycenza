<script setup lang="ts">
import {
    portionLabels,
    reasonLabels,
    requestStatusLabels,
    typeName,
    units,
    type LeaveTypeRow,
} from '../../../leave';

/**
 * HRX.4 — one of my leave requests: its status, the exact days charged at
 * approval, and its decisions (never who decided, never the ledger).
 */
interface Props {
    request: {
        id: string;
        leaveTypeId: string;
        startsOn: string;
        startPortion: string;
        endsOn: string;
        endPortion: string;
        reasonCode: string | null;
        submittedUnits: number;
        status: string;
        days: { date: string; portion: string; units: number }[];
        decisions: {
            id: string;
            decision: string;
            path: string;
            reasonCode: string | null;
            decidedAt: string;
        }[];
    };
    types: LeaveTypeRow[];
}

defineProps<Props>();

const pathLabels: Record<string, string> = {
    self: 'by you',
    manager: 'by your manager',
    administrative: 'by the School',
};
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/my-leave">← My leave</a>
        <h1 class="mt-2 text-xl font-semibold">{{ typeName(types, request.leaveTypeId) }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ request.startsOn }} ({{ portionLabels[request.startPortion] }}) →
            {{ request.endsOn }} ({{ portionLabels[request.endPortion] }}) ·
            {{ requestStatusLabels[request.status] ?? request.status }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Requested {{ units(request.submittedUnits)
            }}<span v-if="request.reasonCode">
                · {{ reasonLabels[request.reasonCode] ?? request.reasonCode }}</span
            >
        </p>

        <h2 class="mt-6 text-sm font-semibold">Days charged</h2>
        <p v-if="request.days.length === 0" class="mt-2 text-sm text-slate-500">
            Days are fixed when the request is approved.
        </p>
        <ul v-else class="mt-2 space-y-1 text-sm">
            <li v-for="d in request.days" :key="d.date">
                {{ d.date }} · {{ portionLabels[d.portion] }} · {{ units(d.units) }}
            </li>
        </ul>

        <h2 class="mt-6 text-sm font-semibold">History</h2>
        <ul class="mt-2 space-y-1 text-sm">
            <li v-for="d in request.decisions" :key="d.id">
                {{ requestStatusLabels[d.decision] ?? d.decision }} {{ pathLabels[d.path] ?? '' }}
                <span v-if="d.reasonCode"> · {{ reasonLabels[d.reasonCode] ?? d.reasonCode }}</span>
                · {{ d.decidedAt.slice(0, 10) }}
            </li>
        </ul>
    </main>
</template>
