<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import {
    employeeName,
    portionLabels,
    reasonLabels,
    requestStatusLabels,
    typeName,
    units,
    type EmployeeLabel,
    type LeaveTypeRow,
} from '../../../leave';
import { useLeaveError } from '../../../leave';

/**
 * HRX.2 — leave approvals for a manager.
 *
 * Only your current direct reports' requests are listed, as resolved by the
 * server from the HR reporting line at this moment. If the reporting line
 * changes, the request moves to the new manager. You cannot decide your own
 * request.
 */
interface RequestRow {
    id: string;
    employmentRecordId: string;
    leaveTypeId: string;
    startsOn: string;
    startPortion: string;
    endsOn: string;
    endPortion: string;
    submittedUnits: number;
    status: string;
}

defineProps<{
    requests: RequestRow[];
    employees: Record<string, EmployeeLabel>;
    types: LeaveTypeRow[];
    rejectionReasons: string[];
}>();

const rejecting = ref<string | null>(null);
const form = useForm({ reason_code: '' });

function approve(id: string): void {
    form.reason_code = '';
    form.post(`/app/leave/approvals/${id}/approve`, { preserveScroll: true });
}

function reject(): void {
    if (rejecting.value === null) {
        return;
    }
    form.post(`/app/leave/approvals/${rejecting.value}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
            form.reset();
        },
    });
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Leave approvals</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Requests of the staff who currently report to you.
        </p>

        <EmptyState
            v-if="requests.length === 0"
            class="mt-8"
            title="Nothing to decide"
            description="None of your direct reports has a submitted or approved request."
        />
        <table v-else class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Employee</th>
                    <th class="py-2">Leave type</th>
                    <th class="py-2">Period</th>
                    <th class="py-2">Units</th>
                    <th class="py-2">Status</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in requests" :key="row.id" class="border-b border-slate-100">
                    <td class="py-2">{{ employeeName(employees, row.employmentRecordId) }}</td>
                    <td class="py-2">{{ typeName(types, row.leaveTypeId) }}</td>
                    <td class="py-2">
                        {{ row.startsOn }} ({{ portionLabels[row.startPortion] }}) →
                        {{ row.endsOn }} ({{ portionLabels[row.endPortion] }})
                    </td>
                    <td class="py-2">{{ units(row.submittedUnits) }}</td>
                    <td class="py-2">{{ requestStatusLabels[row.status] }}</td>
                    <td class="space-x-3 py-2 text-right">
                        <template v-if="row.status === 'submitted'">
                            <button type="button" class="underline" @click="approve(row.id)">
                                Approve
                            </button>
                            <button type="button" class="underline" @click="rejecting = row.id">
                                Reject
                            </button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>

        <form
            v-if="rejecting !== null"
            class="mt-6 rounded border border-slate-300 p-4"
            @submit.prevent="reject"
        >
            <h2 class="text-sm font-semibold">Reject request</h2>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <label class="text-sm text-slate-600" for="reject-reason">Reason</label>
                <select
                    id="reject-reason"
                    v-model="form.reason_code"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a reason</option>
                    <option v-for="r in rejectionReasons" :key="r" :value="r">
                        {{ reasonLabels[r] ?? r }}
                    </option>
                </select>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="form.processing"
                >
                    Reject
                </button>
                <button type="button" class="text-sm underline" @click="rejecting = null">
                    Back
                </button>
            </div>
            <p v-if="form.errors.reason_code" class="mt-2 text-sm text-red-700">
                {{ form.errors.reason_code }}
            </p>
        </form>
    </main>
</template>
