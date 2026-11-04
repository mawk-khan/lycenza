<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
    // ORG.9C-1 (ADR 0076 §69): narrowing filters (employee, type, overlapping dates).
    filters: { employment_record_id: string; leave_type_id: string; from: string; to: string };
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
const filterEmployee = ref(props.filters.employment_record_id);
const filterType = ref(props.filters.leave_type_id);
const filterFrom = ref(props.filters.from);
const filterTo = ref(props.filters.to);
const filterErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

function applyFilters(): void {
    router.get(
        '/app/leave/requests',
        {
            status: status.value || undefined,
            employment_record_id: filterEmployee.value || undefined,
            leave_type_id: filterType.value || undefined,
            from: filterFrom.value || undefined,
            to: filterTo.value || undefined,
        },
        { preserveState: false, replace: true },
    );
}
watch(status, applyFilters);

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
    <main class="mx-auto max-w-6xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Leave requests</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ leaveError }}
        </p>
        <p class="mt-1 text-sm text-ink-muted">
            Administration of staff leave. Units are half-days (2 = one full day). Only working time
            in the staff calendar is charged.
        </p>
        <nav class="mt-3 flex flex-wrap gap-4 text-sm">
            <a class="underline" href="/app/leave/configuration">Configuration</a>
            <a class="underline" href="/app/leave/entitlements">Entitlements</a>
            <a class="underline" href="/app/leave/year-close">Year close</a>
            <a class="underline" href="/app/leave/monthly">Leave by month</a>
        </nav>

        <div class="mt-6">
            <label class="block text-sm text-ink-muted" for="filter-status">Status</label>
            <select id="filter-status" v-model="status" class="input mt-1 w-56">
                <option value="">All</option>
                <option v-for="s in statuses" :key="s" :value="s">
                    {{ requestStatusLabels[s] }}
                </option>
            </select>
        </div>
        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-employee">Employee</label>
                <select id="filter-employee" v-model="filterEmployee" class="input mt-1 w-56">
                    <option value="">All employees</option>
                    <option
                        v-for="e in employments"
                        :key="e.employmentRecordId"
                        :value="e.employmentRecordId"
                    >
                        {{ e.fullName ?? '—' }} ({{ e.employeeNumber ?? '—' }})
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-type">Leave type</label>
                <select id="filter-type" v-model="filterType" class="input mt-1 w-auto">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.id" :value="t.id">
                        {{ t.name }} ({{ t.code }})
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-from">On or after</label>
                <input
                    id="filter-from"
                    v-model="filterFrom"
                    type="date"
                    class="input mt-1 w-auto"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-to">On or before</label>
                <input id="filter-to" v-model="filterTo" type="date" class="input mt-1 w-auto" />
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
        <p
            v-if="filterErrors.to || filterErrors.from"
            class="mt-2 text-sm text-danger"
            role="alert"
        >
            {{ filterErrors.to ?? filterErrors.from }}
        </p>

        <EmptyState
            v-if="requests.length === 0"
            class="mt-8"
            title="No leave requests"
            description="No request matches this filter."
        />
        <table v-else class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-line text-left text-ink-muted">
                    <th class="py-2">Employee</th>
                    <th class="py-2">Leave type</th>
                    <th class="py-2">Period</th>
                    <th class="py-2">Units</th>
                    <th class="py-2">Status</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in requests" :key="row.id" class="border-b border-line">
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
            class="mt-6 rounded-xs border border-control-line p-4"
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
                <label class="text-sm text-ink-muted" for="decision-reason">Reason</label>
                <select
                    id="decision-reason"
                    v-model="decisionForm.reason_code"
                    class="input w-auto"
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
                <button type="submit" class="btn btn-primary" :disabled="decisionForm.processing">
                    Confirm
                </button>
                <button type="button" class="text-sm underline" @click="acting = null">Back</button>
            </div>
            <p v-if="decisionForm.errors.reason_code" class="mt-2 text-sm text-danger">
                {{ decisionForm.errors.reason_code }}
            </p>
        </form>

        <form
            v-if="canManage"
            class="mt-8 rounded-xs border border-control-line p-4"
            @submit.prevent="submitRequest"
        >
            <h2 class="text-sm font-semibold">Submit a request on an employee's behalf</h2>
            <p class="mt-1 text-sm text-ink-muted">
                One day: choose full, first half or second half. Several days: the first day may
                start at the second half and the last day may end after the first half.
            </p>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <label class="block text-sm text-ink-muted" for="req-employee">Employee</label>
                    <select
                        id="req-employee"
                        v-model="submitForm.employment_record_id"
                        class="input mt-1"
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
                    <label class="block text-sm text-ink-muted" for="req-type">Leave type</label>
                    <select id="req-type" v-model="submitForm.leave_type_id" class="input mt-1">
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
                    <label class="block text-sm text-ink-muted" for="req-from">From</label>
                    <div class="mt-1 flex gap-2">
                        <input
                            id="req-from"
                            v-model="submitForm.starts_on"
                            type="date"
                            class="input w-auto"
                        />
                        <select
                            v-model="submitForm.start_portion"
                            aria-label="First day portion"
                            class="input w-auto"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="req-to"
                        >To (leave empty for one day)</label
                    >
                    <div class="mt-1 flex gap-2">
                        <input
                            id="req-to"
                            v-model="submitForm.ends_on"
                            type="date"
                            class="input w-auto"
                        />
                        <select
                            v-model="submitForm.end_portion"
                            aria-label="Last day portion"
                            class="input w-auto"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="req-reason"
                        >Reason (optional)</label
                    >
                    <select id="req-reason" v-model="submitForm.reason_code" class="input mt-1">
                        <option value="">No reason</option>
                        <option v-for="r in requestReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-4" :disabled="submitForm.processing">
                Submit request
            </button>
            <p
                v-for="(message, field) in submitForm.errors"
                :key="field"
                class="mt-2 text-sm text-danger"
            >
                {{ message }}
            </p>
        </form>
    </main>
</template>
