<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import {
    portionLabels,
    reasonLabels,
    requestStatusLabels,
    typeName,
    units,
    useLeaveError,
    type LeaveTypeRow,
} from '../../../leave';

/**
 * HRX.4 — My Leave (staff self-service).
 *
 * Your own balances, your requests, a new request, and withdrawing or
 * cancelling your own. Entitlements are what your School configured — this
 * page makes no statutory claim. Reasons come from closed lists only: never
 * type health details anywhere. Units are half-days (2 = one full day).
 */
interface RequestRow {
    id: string;
    leaveTypeId: string;
    startsOn: string;
    startPortion: string;
    endsOn: string;
    endPortion: string;
    reasonCode: string | null;
    submittedUnits: number;
    status: string;
}

interface Balance {
    leaveTypeId: string;
    leaveTypeName: string | null;
    credits: number;
    debits: number;
    availableUnits: number;
}

interface Props {
    available: boolean;
    overview?: {
        asOf: string;
        leaveYear: { label: string; startsOn: string; endsOn: string } | null;
        types: LeaveTypeRow[];
        balances: Balance[];
    };
    requests?: RequestRow[];
    requestReasons?: string[];
    closingReasons?: string[];
    portions?: string[];
}

const props = defineProps<Props>();

const form = useForm({
    leave_type_id: '',
    starts_on: '',
    start_portion: 'full',
    ends_on: '',
    end_portion: 'full',
    reason_code: '',
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        ends_on: data.ends_on || data.starts_on,
        end_portion:
            data.ends_on && data.ends_on !== data.starts_on ? data.end_portion : data.start_portion,
        reason_code: data.reason_code || null,
    })).post('/app/my-leave/requests', { preserveScroll: true, onSuccess: () => form.reset() });
}

const acting = ref<{ id: string; action: 'withdraw' | 'cancel' } | null>(null);
const decision = useForm({ reason_code: '' });

function confirmDecision(): void {
    if (acting.value === null) {
        return;
    }
    decision.post(`/app/my-leave/requests/${acting.value.id}/${acting.value.action}`, {
        preserveScroll: true,
        onSuccess: () => {
            acting.value = null;
            decision.reset();
        },
    });
}

function period(row: RequestRow): string {
    if (row.startsOn === row.endsOn) {
        return `${row.startsOn} · ${portionLabels[row.startPortion]}`;
    }
    return `${row.startsOn} (${portionLabels[row.startPortion]}) → ${row.endsOn} (${portionLabels[row.endPortion]})`;
}

function cancellable(row: RequestRow): boolean {
    return (
        row.status === 'approved' &&
        props.overview !== undefined &&
        row.startsOn > props.overview.asOf
    );
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">My leave</h1>

        <EmptyState
            v-if="!available"
            class="mt-8"
            title="No staff record for you in this School"
            description="Your account is not linked to a current employment here. Ask your School administrator."
        />
        <template v-else-if="overview">
            <p
                v-if="leaveError"
                role="alert"
                class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
            >
                {{ leaveError }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                Balances come from your School's leave configuration. Units are half-days (2 = one
                full day).
            </p>

            <h2 class="mt-6 text-sm font-semibold">
                Balances
                <span v-if="overview.leaveYear" class="font-normal text-slate-500"
                    >· leave year {{ overview.leaveYear.label }}</span
                >
            </h2>
            <p v-if="overview.balances.length === 0" class="mt-2 text-sm text-slate-500">
                No leave balance has been recorded for you this year.
            </p>
            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Leave type</th>
                        <th class="py-2">Available</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="b in overview.balances"
                        :key="b.leaveTypeId"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ b.leaveTypeName ?? '—' }}</td>
                        <td class="py-2">{{ units(b.availableUnits) }}</td>
                    </tr>
                </tbody>
            </table>

            <form class="mt-8 rounded border border-slate-300 p-4" @submit.prevent="submit">
                <h2 class="text-sm font-semibold">Request leave</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-sm">
                        <span class="block text-slate-600">Leave type</span>
                        <select
                            v-model="form.leave_type_id"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        >
                            <option value="">Select</option>
                            <option v-for="t in overview.types" :key="t.id" :value="t.id">
                                {{ t.name }} ({{ t.code }})
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">From</span>
                        <input
                            v-model="form.starts_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        />
                        <select
                            v-model="form.start_portion"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">To (leave empty for one day)</span>
                        <input
                            v-model="form.ends_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        />
                        <select
                            v-model="form.end_portion"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        >
                            <option v-for="p in portions" :key="p" :value="p">
                                {{ portionLabels[p] }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">Reason (optional)</span>
                        <select
                            v-model="form.reason_code"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        >
                            <option value="">None</option>
                            <option v-for="r in requestReasons" :key="r" :value="r">
                                {{ reasonLabels[r] ?? r }}
                            </option>
                        </select>
                    </label>
                </div>
                <button
                    type="submit"
                    class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="form.processing"
                >
                    Submit request
                </button>
            </form>

            <h2 class="mt-8 text-sm font-semibold">My requests</h2>
            <EmptyState
                v-if="!requests || requests.length === 0"
                class="mt-4"
                title="No requests yet"
                description="Requests you submit appear here with their status."
            />
            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Leave type</th>
                        <th class="py-2">Period</th>
                        <th class="py-2">Units</th>
                        <th class="py-2">Status</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in requests" :key="row.id" class="border-b border-slate-100">
                        <td class="py-2">{{ typeName(overview.types, row.leaveTypeId) }}</td>
                        <td class="py-2">
                            <a class="underline" :href="`/app/my-leave/requests/${row.id}`">{{
                                period(row)
                            }}</a>
                        </td>
                        <td class="py-2">{{ units(row.submittedUnits) }}</td>
                        <td class="py-2">{{ requestStatusLabels[row.status] ?? row.status }}</td>
                        <td class="space-x-3 py-2 text-right">
                            <button
                                v-if="row.status === 'submitted'"
                                type="button"
                                class="underline"
                                @click="acting = { id: row.id, action: 'withdraw' }"
                            >
                                Withdraw
                            </button>
                            <button
                                v-if="cancellable(row)"
                                type="button"
                                class="underline"
                                @click="acting = { id: row.id, action: 'cancel' }"
                            >
                                Cancel
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="acting"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="confirmDecision"
            >
                <h2 class="text-sm font-semibold">
                    {{ acting.action === 'withdraw' ? 'Withdraw request' : 'Cancel leave' }}
                </h2>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-slate-600" for="my-reason">Reason</label>
                    <select
                        id="my-reason"
                        v-model="decision.reason_code"
                        class="rounded border border-slate-300 px-2 py-1 text-sm"
                    >
                        <option value="">Select a reason</option>
                        <option v-for="r in closingReasons" :key="r" :value="r">
                            {{ reasonLabels[r] ?? r }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="decision.processing"
                    >
                        Confirm
                    </button>
                    <button type="button" class="text-sm underline" @click="acting = null">
                        Back
                    </button>
                </div>
                <p v-if="decision.errors.reason_code" class="mt-2 text-sm text-red-700">
                    {{ decision.errors.reason_code }}
                </p>
            </form>
        </template>
    </main>
</template>
