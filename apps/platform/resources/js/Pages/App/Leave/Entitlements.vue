<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    ledgerKindLabels,
    reasonLabels,
    typeName,
    units,
    type EmployeeLabel,
    type LeaveTypeRow,
} from '../../../leave';
import { useLeaveError } from '../../../leave';

/**
 * HRX.1/HRX.2 — leave entitlements (administration).
 *
 * A policy is assigned to an employment for a period. Allocations grant exact
 * units (no proration: a mid-year joiner gets the units you choose), at most
 * one per type and leave year; the annual run grants the policy's units to
 * everyone still without one. Corrections are adjustments with a closed
 * reason. The balance is always the sum of the ledger and never goes below zero.
 */
interface YearRow {
    id: string;
    label: string;
}

interface PolicyRow {
    id: string;
    leaveTypeId: string;
    name: string;
    annualAllocationUnits: number;
    status: string;
}

interface Props {
    employments: EmployeeLabel[];
    years: YearRow[];
    types: LeaveTypeRow[];
    policies: PolicyRow[];
    employmentRecordId: string;
    leaveYearId: string;
    assignments: {
        id: string;
        leavePolicyId: string;
        leaveTypeId: string;
        effectiveFrom: string;
        effectiveTo: string | null;
        ended: boolean;
    }[];
    balances: { leaveTypeId: string; credits: number; debits: number; availableUnits: number }[];
    ledger: {
        id: string;
        kind: string;
        direction: string | null;
        units: number;
        reasonCode: string | null;
        createdAt: string | null;
    }[];
    adjustmentReasons: string[];
    runPreview: { candidateCount: number; leaveTypeId: string; error: string | null } | null;
    canManage: boolean;
}

const props = defineProps<Props>();

const employmentRecordId = ref(props.employmentRecordId);
const leaveYearId = ref(props.leaveYearId);
watch([employmentRecordId, leaveYearId], () => {
    router.get(
        '/app/leave/entitlements',
        {
            employment_record_id: employmentRecordId.value || undefined,
            leave_year_id: leaveYearId.value || undefined,
        },
        { preserveState: false, replace: true },
    );
});

const assignForm = useForm({ leave_policy_id: '', effective_from: '', effective_to: '' });
const allocateForm = useForm({ leave_type_id: '', units: 0 });
const adjustForm = useForm({ leave_type_id: '', direction: 'credit', units: 0, reason: '' });
const runForm = useForm({ leave_type_id: '' });
const runTypeId = ref(props.runPreview?.leaveTypeId ?? '');

function assign(): void {
    assignForm
        .transform((d) => ({
            ...d,
            employment_record_id: employmentRecordId.value,
            effective_to: d.effective_to || null,
        }))
        .post('/app/leave/assignments', {
            preserveScroll: true,
            onSuccess: () => assignForm.reset(),
        });
}

const endingId = ref<string | null>(null);
const endForm = useForm({ effective_to: '' });

function endAssignment(): void {
    if (endingId.value !== null) {
        endForm.post(`/app/leave/assignments/${endingId.value}/end`, {
            preserveScroll: true,
            onSuccess: () => {
                endingId.value = null;
                endForm.reset();
            },
        });
    }
}

function allocate(): void {
    allocateForm
        .transform((d) => ({
            ...d,
            employment_record_id: employmentRecordId.value,
            leave_year_id: leaveYearId.value,
        }))
        .post('/app/leave/allocations', {
            preserveScroll: true,
            onSuccess: () => allocateForm.reset(),
        });
}

function adjust(): void {
    adjustForm
        .transform((d) => ({
            ...d,
            employment_record_id: employmentRecordId.value,
            leave_year_id: leaveYearId.value,
        }))
        .post('/app/leave/adjustments', {
            preserveScroll: true,
            onSuccess: () => adjustForm.reset(),
        });
}

function previewRun(): void {
    router.get(
        '/app/leave/entitlements',
        {
            employment_record_id: employmentRecordId.value || undefined,
            leave_year_id: leaveYearId.value || undefined,
            run_leave_type_id: runTypeId.value || undefined,
        },
        { preserveScroll: true },
    );
}

function executeRun(): void {
    runForm
        .transform(() => ({ leave_type_id: runTypeId.value, leave_year_id: leaveYearId.value }))
        .post('/app/leave/allocation-runs', { preserveScroll: true });
}

function policyName(id: string): string {
    return props.policies.find((p) => p.id === id)?.name ?? '—';
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave entitlements</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError }}
        </p>

        <div class="mt-6 flex flex-wrap gap-6">
            <div>
                <label class="block text-sm text-slate-600" for="ent-employee">Employee</label>
                <select
                    id="ent-employee"
                    v-model="employmentRecordId"
                    class="mt-1 w-80 rounded border border-slate-300 px-3 py-2 text-sm"
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
                <label class="block text-sm text-slate-600" for="ent-year">Leave year</label>
                <select
                    id="ent-year"
                    v-model="leaveYearId"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a year</option>
                    <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
                </select>
            </div>
        </div>

        <template v-if="employmentRecordId">
            <h2 class="mt-8 text-sm font-semibold">Balances</h2>
            <ul class="mt-2 text-sm">
                <li v-for="b in balances" :key="b.leaveTypeId">
                    {{ typeName(types, b.leaveTypeId) }}: {{ units(b.availableUnits) }} available
                </li>
                <li v-if="balances.length === 0" class="text-slate-500">
                    No ledger entries in this year.
                </li>
            </ul>

            <h2 class="mt-6 text-sm font-semibold">Ledger</h2>
            <ul class="mt-2 text-sm">
                <li v-for="e in ledger" :key="e.id">
                    {{ ledgerKindLabels[e.kind] }}{{ e.direction ? ` (${e.direction})` : '' }} ·
                    {{ e.units }} units
                    <template v-if="e.reasonCode">· {{ reasonLabels[e.reasonCode] }}</template> ·
                    {{ e.createdAt }}
                </li>
                <li v-if="ledger.length === 0" class="text-slate-500">None.</li>
            </ul>

            <h2 class="mt-6 text-sm font-semibold">Policy assignments</h2>
            <ul class="mt-2 text-sm">
                <li v-for="a in assignments" :key="a.id">
                    {{ typeName(types, a.leaveTypeId) }} · {{ policyName(a.leavePolicyId) }} ·
                    {{ a.effectiveFrom }} →
                    {{ a.effectiveTo ?? 'open-ended' }}
                    <button
                        v-if="canManage && !a.ended"
                        type="button"
                        class="ml-2 underline"
                        @click="endingId = a.id"
                    >
                        End
                    </button>
                    <form
                        v-if="endingId === a.id"
                        class="ml-2 inline-flex gap-2"
                        @submit.prevent="endAssignment"
                    >
                        <input
                            v-model="endForm.effective_to"
                            type="date"
                            aria-label="Last effective day"
                            class="rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                        <button type="submit" class="underline">Confirm</button>
                    </form>
                </li>
                <li v-if="assignments.length === 0" class="text-slate-500">No policy assigned.</li>
            </ul>

            <div v-if="canManage" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <form class="rounded border border-slate-300 p-4" @submit.prevent="assign">
                    <h3 class="text-sm font-semibold">Assign a policy</h3>
                    <select
                        v-model="assignForm.leave_policy_id"
                        aria-label="Policy"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a policy</option>
                        <option
                            v-for="p in policies.filter((x) => x.status === 'active')"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ typeName(types, p.leaveTypeId) }} · {{ p.name }}
                        </option>
                    </select>
                    <input
                        v-model="assignForm.effective_from"
                        type="date"
                        aria-label="From"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    />
                    <input
                        v-model="assignForm.effective_to"
                        type="date"
                        aria-label="To (optional)"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    />
                    <button type="submit" class="mt-2 underline" :disabled="assignForm.processing">
                        Assign
                    </button>
                </form>
                <form
                    v-if="leaveYearId"
                    class="rounded border border-slate-300 p-4"
                    @submit.prevent="allocate"
                >
                    <h3 class="text-sm font-semibold">Explicit allocation (exact units)</h3>
                    <select
                        v-model="allocateForm.leave_type_id"
                        aria-label="Leave type"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a leave type</option>
                        <option
                            v-for="t in types.filter((x) => x.tracksBalance)"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }} ({{ t.code }})
                        </option>
                    </select>
                    <input
                        v-model.number="allocateForm.units"
                        type="number"
                        min="1"
                        aria-label="Units"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    />
                    <button
                        type="submit"
                        class="mt-2 underline"
                        :disabled="allocateForm.processing"
                    >
                        Allocate
                    </button>
                </form>
                <form
                    v-if="leaveYearId"
                    class="rounded border border-slate-300 p-4"
                    @submit.prevent="adjust"
                >
                    <h3 class="text-sm font-semibold">Adjustment</h3>
                    <select
                        v-model="adjustForm.leave_type_id"
                        aria-label="Leave type"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a leave type</option>
                        <option
                            v-for="t in types.filter((x) => x.tracksBalance)"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }} ({{ t.code }})
                        </option>
                    </select>
                    <select
                        v-model="adjustForm.direction"
                        aria-label="Direction"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="credit">Credit</option>
                        <option value="debit">Debit</option>
                    </select>
                    <input
                        v-model.number="adjustForm.units"
                        type="number"
                        min="1"
                        aria-label="Units"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    />
                    <select
                        v-model="adjustForm.reason"
                        aria-label="Reason"
                        class="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a reason</option>
                        <option v-for="r in adjustmentReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                    <button type="submit" class="mt-2 underline" :disabled="adjustForm.processing">
                        Record
                    </button>
                </form>
            </div>
        </template>

        <section v-if="canManage && leaveYearId" class="mt-8 rounded border border-slate-300 p-4">
            <h2 class="text-sm font-semibold">Annual allocation run</h2>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <select
                    v-model="runTypeId"
                    aria-label="Run leave type"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a leave type</option>
                    <option
                        v-for="t in types.filter((x) => x.tracksBalance)"
                        :key="t.id"
                        :value="t.id"
                    >
                        {{ t.name }} ({{ t.code }})
                    </option>
                </select>
                <button type="button" class="underline" :disabled="!runTypeId" @click="previewRun">
                    Preview
                </button>
                <span v-if="runPreview" class="text-sm">
                    {{
                        runPreview.error
                            ? runPreview.error
                            : `${runPreview.candidateCount} employment(s) would be allocated`
                    }}
                </span>
                <button
                    v-if="runPreview && !runPreview.error && runPreview.leaveTypeId === runTypeId"
                    type="button"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="runForm.processing"
                    @click="executeRun"
                >
                    Execute run
                </button>
            </div>
        </section>
    </main>
</template>
