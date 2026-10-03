<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
 * HRX.2 — Leave requests (administration).
 *
 * Administrators submit a request on an employee's behalf and decide it.
 * Submission consumes nothing; approval writes the exact chargeable days and
 * consumes the balance, and cancellation reverses it with a new ledger entry.
 * Reasons come from closed lists only: never type health details anywhere.
 * Nobody can approve or reject their own request.
 */
interface RequestRow {
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
}

interface Props {
    requests: RequestRow[];
    status: string;
    statuses: string[];
    employees: Record<string, EmployeeLabel>;
    employments: EmployeeLabel[];
    types: LeaveTypeRow[];
    portions: string[];
    requestReasons: string[];
    rejectionReasons: string[];
    closingReasons: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const status = ref(props.status);
watch(status, () => {
    router.get(
        '/app/leave/requests',
        { status: status.value || undefined },
        { preserveState: false, replace: true },
    );
});

const submitForm = useForm({
    employment_record_id: '',
    leave_type_id: '',
    starts_on: '',
    start_portion: 'full',
    ends_on: '',
    end_portion: 'full',
    reason_code: '',
});

function submitRequest(): void {
    submitForm
        .transform((data) => ({
            ...data,
            ends_on: data.ends_on || data.starts_on,
            reason_code: data.reason_code || null,
        }))
        .post('/app/leave/requests', { preserveScroll: true, onSuccess: () => submitForm.reset() });
}

const acting = ref<{ id: string; action: 'reject' | 'withdraw' | 'cancel' } | null>(null);
const decisionForm = useForm({ reason_code: '' });

function decide(row: RequestRow, action: 'approve' | 'reject' | 'withdraw' | 'cancel'): void {
    if (action === 'approve') {
        decisionForm.reason_code = '';
        decisionForm.post(`/app/leave/requests/${row.id}/approve`, { preserveScroll: true });
        return;
    }
    acting.value = { id: row.id, action };
    decisionForm.reset();
    decisionForm.clearErrors();
}

function confirmDecision(): void {
    if (acting.value === null) {
        return;
    }
    decisionForm.post(`/app/leave/requests/${acting.value.id}/${acting.value.action}`, {
        preserveScroll: true,
        onSuccess: () => {
            acting.value = null;
            decisionForm.reset();
        },
    });
}

function period(row: RequestRow): string {
    if (row.startsOn === row.endsOn) {
        return `${row.startsOn} · ${portionLabels[row.startPortion]}`;
    }
    return `${row.startsOn} (${portionLabels[row.startPortion]}) → ${row.endsOn} (${portionLabels[row.endPortion]})`;
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Leave requests</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError }}
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Administration of staff leave. Units are half-days (2 = one full day). Only working time
            in the staff calendar is charged.
        </p>
        <nav class="mt-3 flex flex-wrap gap-4 text-sm">
            <a class="underline" href="/app/leave/configuration">Configuration</a>
            <a class="underline" href="/app/leave/entitlements">Entitlements</a>
            <a class="underline" href="/app/leave/year-close">Year close</a>
        </nav>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-status">Status</label>
            <select
                id="filter-status"
                v-model="status"
                class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option value="">All</option>
                <option v-for="s in statuses" :key="s" :value="s">
                    {{ requestStatusLabels[s] }}
                </option>
            </select>
        </div>

        <EmptyState
            v-if="requests.length === 0"
            class="mt-8"
            title="No leave requests"
            description="No request matches this filter."
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
                    <td class="py-2">{{ period(row) }}</td>
                    <td class="py-2">{{ units(row.submittedUnits) }}</td>
                    <td class="py-2">{{ requestStatusLabels[row.status] }}</td>
                    <td class="space-x-3 py-2 text-right">
                        <a class="underline" :href="`/app/leave/requests/${row.id}`">Details</a>
                        <template v-if="canManage && row.status === 'submitted'">
                            <button type="button" class="underline" @click="decide(row, 'approve')">
                                Approve
                            </button>
                            <button type="button" class="underline" @click="decide(row, 'reject')">
                                Reject
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="decide(row, 'withdraw')"
                            >
                                Withdraw
                            </button>
                        </template>
                        <button
                            v-if="canManage && row.status === 'approved'"
                            type="button"
                            class="underline"
                            @click="decide(row, 'cancel')"
                        >
                            Cancel
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <form
            v-if="canManage && acting !== null"
            class="mt-6 rounded border border-slate-300 p-4"
            @submit.prevent="confirmDecision"
        >
            <h2 class="text-sm font-semibold">
                {{
                    acting.action === 'reject'
                        ? 'Reject'
                        : acting.action === 'withdraw'
                          ? 'Withdraw'
                          : 'Cancel'
                }}
                request
            </h2>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <label class="text-sm text-slate-600" for="decision-reason">Reason</label>
                <select
                    id="decision-reason"
                    v-model="decisionForm.reason_code"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a reason</option>
                    <option
                        v-for="reason in acting.action === 'reject'
                            ? rejectionReasons
                            : closingReasons"
                        :key="reason"
                        :value="reason"
                    >
                        {{ reasonLabels[reason] ?? reason }}
                    </option>
                </select>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="decisionForm.processing"
                >
                    Confirm
                </button>
                <button type="button" class="text-sm underline" @click="acting = null">Back</button>
            </div>
            <p v-if="decisionForm.errors.reason_code" class="mt-2 text-sm text-red-700">
                {{ decisionForm.errors.reason_code }}
            </p>
        </form>

        <form
            v-if="canManage"
            class="mt-8 rounded border border-slate-300 p-4"
            @submit.prevent="submitRequest"
        >
            <h2 class="text-sm font-semibold">Submit a request on an employee's behalf</h2>
            <p class="mt-1 text-sm text-slate-500">
                One day: choose full, first half or second half. Several days: the first day may
                start at the second half and the last day may end after the first half.
            </p>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <label class="block text-sm text-slate-600" for="req-employee">Employee</label>
                    <select
                        id="req-employee"
                        v-model="submitForm.employment_record_id"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">Select an employee</option>
                        <option
                            v-for="e in employments"
                            :key="e.employmentRecordId"
                            :value="e.employmentRecordId"
                        >
                            {{ e.fullName }} ({{ e.employeeNumber }})
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="req-type">Leave type</label>
                    <select
                        id="req-type"
                        v-model="submitForm.leave_type_id"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">Select a leave type</option>
                        <option
                            v-for="t in types.filter((x) => x.status === 'active')"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }} ({{ t.code }})
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="req-from">From</label>
                    <div class="mt-1 flex gap-2">
                        <input
                            id="req-from"
                            v-model="submitForm.starts_on"
                            type="date"
                            class="rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <select
                            v-model="submitForm.start_portion"
                            aria-label="First day portion"
                            class="rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="req-to"
                        >To (leave empty for one day)</label
                    >
                    <div class="mt-1 flex gap-2">
                        <input
                            id="req-to"
                            v-model="submitForm.ends_on"
                            type="date"
                            class="rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <select
                            v-model="submitForm.end_portion"
                            aria-label="Last day portion"
                            class="rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="req-reason"
                        >Reason (optional)</label
                    >
                    <select
                        id="req-reason"
                        v-model="submitForm.reason_code"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">No reason</option>
                        <option v-for="r in requestReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                </div>
            </div>
            <button
                type="submit"
                class="mt-4 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                :disabled="submitForm.processing"
            >
                Submit request
            </button>
            <p
                v-for="(message, field) in submitForm.errors"
                :key="field"
                class="mt-2 text-sm text-red-700"
            >
                {{ message }}
            </p>
        </form>
    </main>
</template>
