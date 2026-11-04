<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    portionLabels,
    reasonLabels,
    requestStatusLabels,
    typeName,
    units,
    useLeaveError,
    type EmployeeLabel,
    type LeaveTypeRow,
} from '../../../leave';

/**
 * HR.11 (ADR 0065 §30.3) — one leave request of a current direct report:
 * what was asked, the days charged if approved, and the decision history as
 * recorded (who decided, on which path, when, with which closed reason). A
 * later change of manager never rewrites it. Only leave details are shown,
 * never the person's HR profile.
 */
interface Decision {
    id: string;
    decision: string;
    path: string;
    reasonCode: string | null;
    decidedAt: string;
    decidedBy: string | null;
}
const props = defineProps<{
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
        submittedAt: string | null;
        days: { date: string; portion: string; units: number }[];
        decisions: Decision[];
    };
    employee: EmployeeLabel | null;
    types: LeaveTypeRow[];
    rejectionReasons: string[];
}>();

const leaveError = useLeaveError();
const form = useForm({ reason_code: '' });
const rejecting = ref(false);
const pathLabels: Record<string, string> = {
    manager: 'Line manager',
    administrative: 'Leave administrator',
    self: 'The employee',
};

function approve(): void {
    form.reason_code = '';
    form.post(`/app/leave/approvals/${props.request.id}/approve`, { preserveScroll: true });
}

function reject(): void {
    form.post(`/app/leave/approvals/${props.request.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/approvals">← Leave approvals</a>
        <h1 class="mt-2 text-xl font-semibold">
            {{ employee?.fullName ?? 'Leave request' }}
            <span v-if="employee?.employeeNumber" class="text-slate-500"
                >({{ employee.employeeNumber }})</span
            >
        </h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError }}
        </p>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <dt class="text-slate-500">Leave type</dt>
            <dd>{{ typeName(types, request.leaveTypeId) }}</dd>
            <dt class="text-slate-500">Period</dt>
            <dd>
                {{ request.startsOn }} ({{ portionLabels[request.startPortion] }}) →
                {{ request.endsOn }} ({{ portionLabels[request.endPortion] }})
            </dd>
            <dt class="text-slate-500">Units</dt>
            <dd>{{ units(request.submittedUnits) }}</dd>
            <dt class="text-slate-500">Reason</dt>
            <dd>
                {{
                    request.reasonCode
                        ? (reasonLabels[request.reasonCode] ?? request.reasonCode)
                        : '—'
                }}
            </dd>
            <dt class="text-slate-500">Status</dt>
            <dd>{{ requestStatusLabels[request.status] ?? request.status }}</dd>
            <dt class="text-slate-500">Submitted</dt>
            <dd>{{ request.submittedAt?.slice(0, 10) ?? '—' }}</dd>
        </dl>

        <div v-if="request.status === 'submitted'" class="mt-6 flex flex-wrap items-end gap-3">
            <button
                type="button"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                :disabled="form.processing"
                @click="approve"
            >
                Approve
            </button>
            <button v-if="!rejecting" type="button" class="underline" @click="rejecting = true">
                Reject
            </button>
            <form v-else class="flex items-end gap-2" @submit.prevent="reject">
                <label class="text-sm"
                    >Reason
                    <select
                        v-model="form.reason_code"
                        class="ml-1 rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a reason</option>
                        <option v-for="r in rejectionReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                </label>
                <button type="submit" class="underline" :disabled="form.processing">Reject</button>
                <button type="button" class="underline" @click="rejecting = false">Back</button>
            </form>
            <p v-if="form.errors.reason_code" role="alert" class="w-full text-sm text-red-700">
                {{ form.errors.reason_code }}
            </p>
        </div>

        <section class="mt-8" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-sm font-semibold">Decision history</h2>
            <p v-if="request.decisions.length === 0" class="mt-1 text-sm text-slate-600">
                No decision yet.
            </p>
            <ol class="mt-2 space-y-1 text-sm">
                <li v-for="d in request.decisions" :key="d.id">
                    {{ requestStatusLabels[d.decision] ?? d.decision }} by
                    {{ d.decidedBy ?? 'an account' }} ({{ pathLabels[d.path] ?? d.path }}) on
                    {{ d.decidedAt.slice(0, 10) }}
                    <template v-if="d.reasonCode">
                        · {{ reasonLabels[d.reasonCode] ?? d.reasonCode }}</template
                    >
                </li>
            </ol>
        </section>

        <section v-if="request.days.length > 0" class="mt-6" aria-labelledby="days-heading">
            <h2 id="days-heading" class="text-sm font-semibold">Days charged</h2>
            <ul class="mt-1 text-sm">
                <li v-for="d in request.days" :key="d.date">
                    {{ d.date }} · {{ portionLabels[d.portion] }} · {{ units(d.units) }}
                </li>
            </ul>
        </section>
    </main>
</template>
