<script setup lang="ts">
import { formatDateTime } from '../../../datetime';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    ledgerKindLabels,
    monthLabel,
    reasonLabels,
    type LeaveStatement,
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
 *
 * HR.10 (ADR 0065 §29): an adjustment or an opening balance needs a fresh
 * authentication code (S-7); HR.R1-C35 WO-19 (D-4): so do an explicit
 * allocation and the annual allocation run. Each type's statement shows every entry with its
 * running balance and the identity opening + credits − debits = closing, as
 * the server computed it. A monthly policy is credited from the Accruals page.
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
    statements: LeaveStatement[];
    mfaEnrolled: boolean;
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
const allocateForm = useForm({ leave_type_id: '', units: 0, mfa_code: '' });
const adjustForm = useForm({
    leave_type_id: '',
    direction: 'credit',
    units: 0,
    reason: '',
    corrects_entry_id: '',
    mfa_code: '',
});
const openingForm = useForm({ leave_type_id: '', units: 0, mfa_code: '' });

function recordOpening(): void {
    openingForm
        .transform((d) => ({
            ...d,
            employment_record_id: employmentRecordId.value,
            leave_year_id: leaveYearId.value,
        }))
        .post('/app/leave/openings', {
            preserveScroll: true,
            onSuccess: () => openingForm.reset(),
        });
}
const runForm = useForm({ leave_type_id: '', mfa_code: '' });
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
            corrects_entry_id: d.corrects_entry_id || null,
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
        .transform((d) => ({
            leave_type_id: runTypeId.value,
            leave_year_id: leaveYearId.value,
            mfa_code: d.mfa_code,
        }))
        .post('/app/leave/allocation-runs', {
            preserveScroll: true,
            onSuccess: () => runForm.reset('mfa_code'),
        });
}

function policyName(id: string): string {
    return props.policies.find((p) => p.id === id)?.name ?? '—';
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave entitlements</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ leaveError }}
        </p>

        <div class="mt-6 flex flex-wrap gap-6">
            <div>
                <label class="block text-sm text-ink-muted" for="ent-employee">Employee</label>
                <select id="ent-employee" v-model="employmentRecordId" class="input mt-1 w-80">
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
                <label class="block text-sm text-ink-muted" for="ent-year">Leave year</label>
                <select id="ent-year" v-model="leaveYearId" class="input mt-1 w-56">
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
                <li v-if="balances.length === 0" class="text-ink-muted">
                    No ledger entries in this year.
                </li>
            </ul>

            <h2 class="mt-6 text-sm font-semibold">Statements</h2>
            <p v-if="statements.length === 0" class="mt-2 text-sm text-ink-muted">
                No ledger entries in this year.
            </p>
            <section
                v-for="st in statements"
                :key="st.leaveTypeId"
                class="mt-3"
                :aria-label="`Statement: ${typeName(types, st.leaveTypeId)}`"
            >
                <h3 class="text-sm font-medium">{{ typeName(types, st.leaveTypeId) }}</h3>
                <p class="text-sm">
                    Opening {{ units(st.opening) }} + credits {{ units(st.credits) }} − debits
                    {{ units(st.debits) }} = closing {{ units(st.closing) }}
                    <span v-if="!st.identityHolds" role="alert" class="text-danger">
                        (does not reconcile — report this)
                    </span>
                </p>
                <table class="mt-1 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-control-line">
                            <th scope="col" class="py-1 pr-2">Entry</th>
                            <th scope="col" class="py-1 pr-2">Units</th>
                            <th scope="col" class="py-1 pr-2">Balance</th>
                            <th scope="col" class="py-1 pr-2">Source</th>
                            <th scope="col" class="py-1">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in st.lines" :key="line.id" class="border-b border-line">
                            <td class="py-1 pr-2">
                                {{ ledgerKindLabels[line.kind] ?? line.kind
                                }}{{ line.direction ? ` (${line.direction})` : '' }}
                            </td>
                            <td class="py-1 pr-2">
                                {{ line.signedUnits > 0 ? '+' : '' }}{{ line.signedUnits }}
                            </td>
                            <td class="py-1 pr-2">{{ line.runningBalance }}</td>
                            <td class="py-1 pr-2">
                                <template v-if="line.accrualPeriod">{{
                                    monthLabel(line.accrualPeriod)
                                }}</template>
                                <a
                                    v-else-if="line.leaveRequestId"
                                    class="underline"
                                    :href="`/app/leave/requests/${line.leaveRequestId}`"
                                    >Leave request</a
                                >
                                <template v-else-if="line.yearCloseId">Year close</template>
                                <template v-else-if="line.reasonCode">{{
                                    reasonLabels[line.reasonCode] ?? line.reasonCode
                                }}</template>
                                <template v-if="line.correctsEntryId">
                                    · corrects an earlier entry</template
                                >
                            </td>
                            <td class="py-1">{{ formatDateTime(line.createdAt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

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
                            class="input w-auto"
                        />
                        <button type="submit" class="underline">Confirm</button>
                    </form>
                </li>
                <li v-if="assignments.length === 0" class="text-ink-muted">No policy assigned.</li>
            </ul>

            <div v-if="canManage" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <form class="rounded-xs border border-control-line p-4" @submit.prevent="assign">
                    <h3 class="text-sm font-semibold">Assign a policy</h3>
                    <select
                        v-model="assignForm.leave_policy_id"
                        aria-label="Policy"
                        class="input mt-2"
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
                        class="input mt-2"
                    />
                    <input
                        v-model="assignForm.effective_to"
                        type="date"
                        aria-label="To (optional)"
                        class="input mt-2"
                    />
                    <button type="submit" class="mt-2 underline" :disabled="assignForm.processing">
                        Assign
                    </button>
                </form>
                <form
                    v-if="leaveYearId"
                    class="rounded-xs border border-control-line p-4"
                    @submit.prevent="allocate"
                >
                    <h3 class="text-sm font-semibold">Explicit allocation (exact units)</h3>
                    <select
                        v-model="allocateForm.leave_type_id"
                        aria-label="Leave type"
                        class="input mt-2"
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
                        class="input mt-2"
                    />
                    <label class="mt-2 block text-sm"
                        >Authentication code
                        <input
                            v-model="allocateForm.mfa_code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            class="input mt-1"
                        />
                    </label>
                    <p v-if="allocateForm.errors.mfa_code" role="alert" class="text-sm text-danger">
                        {{ allocateForm.errors.mfa_code }}
                    </p>
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
                    class="rounded-xs border border-control-line p-4"
                    @submit.prevent="adjust"
                >
                    <h3 class="text-sm font-semibold">Adjustment</h3>
                    <select
                        v-model="adjustForm.leave_type_id"
                        aria-label="Leave type"
                        class="input mt-2"
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
                        class="input mt-2"
                    >
                        <option value="credit">Credit</option>
                        <option value="debit">Debit</option>
                    </select>
                    <input
                        v-model.number="adjustForm.units"
                        type="number"
                        min="1"
                        aria-label="Units"
                        class="input mt-2"
                    />
                    <select v-model="adjustForm.reason" aria-label="Reason" class="input mt-2">
                        <option value="">Select a reason</option>
                        <option v-for="r in adjustmentReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                    <label class="mt-2 block text-sm"
                        >Corrects (optional)
                        <select v-model="adjustForm.corrects_entry_id" class="input mt-1">
                            <option value="">No earlier entry</option>
                            <template
                                v-for="st in statements.filter(
                                    (x) => x.leaveTypeId === adjustForm.leave_type_id,
                                )"
                                :key="st.leaveTypeId"
                            >
                                <option v-for="line in st.lines" :key="line.id" :value="line.id">
                                    {{ ledgerKindLabels[line.kind] ?? line.kind }}
                                    {{ line.signedUnits }}
                                    {{ line.accrualPeriod ? monthLabel(line.accrualPeriod) : '' }}
                                </option>
                            </template>
                        </select>
                    </label>
                    <label class="mt-2 block text-sm"
                        >Authentication code
                        <input
                            v-model="adjustForm.mfa_code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            class="input mt-1"
                        />
                    </label>
                    <p v-if="adjustForm.errors.mfa_code" role="alert" class="text-sm text-danger">
                        {{ adjustForm.errors.mfa_code }}
                    </p>
                    <button type="submit" class="mt-2 underline" :disabled="adjustForm.processing">
                        Record
                    </button>
                </form>
                <form
                    v-if="leaveYearId"
                    class="rounded-xs border border-control-line p-4"
                    @submit.prevent="recordOpening"
                >
                    <h3 class="text-sm font-semibold">Opening balance (go-live)</h3>
                    <p class="mt-1 text-xs text-ink-muted">
                        Exact units carried over from the School's previous records. Only as the
                        account's first entry, once.
                    </p>
                    <label class="mt-2 block text-sm"
                        >Leave type
                        <select v-model="openingForm.leave_type_id" class="input mt-1">
                            <option value="">Select a leave type</option>
                            <option
                                v-for="t in types.filter((x) => x.tracksBalance)"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }} ({{ t.code }})
                            </option>
                        </select>
                    </label>
                    <label class="mt-2 block text-sm"
                        >Units (half-days)
                        <input
                            v-model.number="openingForm.units"
                            type="number"
                            min="1"
                            class="input mt-1"
                        />
                    </label>
                    <label class="mt-2 block text-sm"
                        >Authentication code
                        <input
                            v-model="openingForm.mfa_code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            class="input mt-1"
                        />
                    </label>
                    <p v-if="openingForm.errors.mfa_code" role="alert" class="text-sm text-danger">
                        {{ openingForm.errors.mfa_code }}
                    </p>
                    <button type="submit" class="mt-2 underline" :disabled="openingForm.processing">
                        Record opening balance
                    </button>
                </form>
            </div>
            <p v-if="canManage && !mfaEnrolled" class="mt-2 text-sm text-ink">
                Allocations, allocation runs, adjustments and opening balances need a current
                authentication code: enroll a factor under Account security first.
            </p>
            <p class="mt-2 text-sm">
                <a class="underline" href="/app/leave/accruals">Monthly accrual →</a>
            </p>
        </template>

        <section
            v-if="canManage && leaveYearId"
            class="mt-8 rounded-xs border border-control-line p-4"
        >
            <h2 class="text-sm font-semibold">Annual allocation run</h2>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <select v-model="runTypeId" aria-label="Run leave type" class="input w-auto">
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
                <template
                    v-if="runPreview && !runPreview.error && runPreview.leaveTypeId === runTypeId"
                >
                    <label class="text-sm"
                        >Authentication code
                        <input
                            v-model="runForm.mfa_code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            class="input ml-1 w-auto"
                        />
                    </label>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="runForm.processing"
                        @click="executeRun"
                    >
                        Execute run
                    </button>
                </template>
            </div>
            <p v-if="runForm.errors.mfa_code" role="alert" class="mt-2 text-sm text-danger">
                {{ runForm.errors.mfa_code }}
            </p>
        </section>
    </main>
</template>
