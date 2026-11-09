<script setup lang="ts">
// ORG.9C-1 (ADR 0076 §69), HR-003: one month's staff attendance per employee,
// counted in days (two halves = one day) by the same state the daily register
// shows. Staff attendance records halves only -- there is no late, no-punch or
// loss-of-pay figure here, and leave is not split into paid and unpaid.
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import RosterPager from '../../../Components/StaffAttendance/RosterPager.vue';
import { formatDate } from '../../../datetime';
import { halfStatusLabel, reasonLabels } from '../../../staffAttendance';

type State =
    'present' | 'absent' | 'leave' | 'holiday' | 'off_day' | 'unrecorded' | 'calendar_not_recorded';

interface Props {
    summary: {
        month: string;
        from: string;
        to: string;
        calendarConfigured: boolean;
        rows: {
            employment: {
                employmentRecordId: string;
                employeeNumber: string | null;
                fullName: string | null;
                status: string;
            };
            halfDays: Record<State, number>;
        }[];
        totals: Record<State, number>;
        page: { page: number; perPage: number; total: number; lastPage: number };
    };
    currentMonth: string;
    /** HR.R1-C18 (S-6): a locked month is never reopened. */
    lock: { month: string; locked: boolean; lockedAt: string | null };
    canLock: boolean;
    hasMfaFactor: boolean;
    requests: LockedCorrection[];
}

type Half = 'present' | 'absent' | null;

interface LockedCorrection {
    id: string;
    recordId: string;
    date: string | null;
    employment: { employeeNumber: string | null; fullName: string | null } | null;
    before: { firstHalf: Half; secondHalf: Half };
    after: { firstHalf: Half; secondHalf: Half };
    reasonCode: string;
    status: 'requested' | 'approved' | 'refused';
    mine: boolean;
    requestedAt: string | null;
    decidedAt: string | null;
    canDecide: boolean;
}

const props = defineProps<Props>();
const statusLabels: Record<LockedCorrection['status'], string> = {
    requested: 'Waiting for approval',
    approved: 'Approved and applied',
    refused: 'Refused',
};

const lockForm = useForm({ confirm: false, mfa_code: '' });
function lockMonth(): void {
    lockForm.post(`/app/staff-attendance/months/${props.summary.month}/lock`, {
        preserveScroll: true,
        onFinish: () => lockForm.reset('mfa_code'),
    });
}

const deciding = ref<string | null>(null);
const decisionForm = useForm({ confirm: false, mfa_code: '' });
function decide(id: string, decision: 'approve' | 'refuse'): void {
    decisionForm.post(`/app/staff-attendance/locked-corrections/${id}/${decision}`, {
        preserveScroll: true,
        onSuccess: () => (deciding.value = null),
        onFinish: () => decisionForm.reset('mfa_code'),
    });
}
function halves(h: { firstHalf: Half; secondHalf: Half }): string {
    return `${halfStatusLabel(h.firstHalf)} / ${halfStatusLabel(h.secondHalf)}`;
}
const month = ref(props.summary.month);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const states: { key: State; label: string }[] = [
    { key: 'present', label: 'Present' },
    { key: 'absent', label: 'Absent' },
    { key: 'leave', label: 'On leave' },
    { key: 'holiday', label: 'Holiday' },
    { key: 'off_day', label: 'Off day' },
    { key: 'unrecorded', label: 'Not recorded' },
    { key: 'calendar_not_recorded', label: 'Calendar not recorded' },
];

function days(halves: number): string {
    return halves === 0 ? '—' : String(halves / 2);
}

function apply(): void {
    router.get(
        '/app/staff-attendance/monthly',
        { month: month.value || undefined },
        { replace: true },
    );
}
</script>

<template>
    <Head title="Staff attendance by month" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/staff-attendance">← Staff attendance</a>
        <h1 class="mt-2 text-xl font-semibold">Staff attendance by month</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Days from {{ summary.from }} to {{ summary.to }}, per employee. Half days count as 0.5.
            Approved leave comes first, then what was recorded, then the staff calendar.
        </p>

        <form class="mt-6 flex items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-ink-muted" for="staff-month">Month</label>
                <input
                    id="staff-month"
                    v-model="month"
                    type="month"
                    :max="currentMonth"
                    class="input mt-1 w-auto"
                    @change="apply"
                />
            </div>
        </form>
        <p v-if="pageErrors.month" class="mt-3 text-sm text-danger" role="alert">
            {{ pageErrors.month }}
        </p>
        <section class="mt-6" aria-labelledby="lock-heading">
            <h2 id="lock-heading" class="text-sm font-semibold">Month lock</h2>
            <p class="mt-1 text-sm" role="status">
                <template v-if="lock.locked">
                    {{ lock.month }} is locked<span v-if="lock.lockedAt">
                        since {{ formatDate(lock.lockedAt) }}</span
                    >. Nothing is recorded or corrected directly any more; a correction needs
                    someone else's approval, and the month stays locked.
                </template>
                <template v-else>{{ lock.month }} is open.</template>
            </p>
            <p v-if="pageErrors.attendance" class="mt-2 text-sm text-danger" role="alert">
                {{ pageErrors.attendance }}
            </p>
            <form
                v-if="canLock && !lock.locked && summary.month < currentMonth"
                class="mt-3 space-y-3 rounded-xs border border-control-line p-4"
                @submit.prevent="lockMonth"
            >
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="lockForm.confirm" type="checkbox" class="mt-1" />
                    I understand that locking {{ summary.month }} cannot be undone.
                </label>
                <p v-if="lockForm.errors.confirm" class="text-sm text-danger">
                    {{ lockForm.errors.confirm }}
                </p>
                <MfaCodeField
                    id="lock-month-mfa"
                    v-model="lockForm.mfa_code"
                    class="w-72"
                    :has-mfa-factor="hasMfaFactor"
                    action="Locking a staff attendance month"
                    :error="lockForm.errors.mfa_code"
                />
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="lockForm.processing || !lockForm.confirm || lockForm.mfa_code === ''"
                >
                    Lock {{ summary.month }}
                </button>
            </form>
        </section>

        <section v-if="requests.length > 0" class="mt-6" aria-labelledby="requests-heading">
            <h2 id="requests-heading" class="text-sm font-semibold">Correction requests</h2>
            <ul class="mt-2 divide-y divide-line">
                <li v-for="r in requests" :key="r.id" class="py-3 text-sm">
                    <p>
                        {{ r.employment?.fullName ?? '—' }}
                        <span class="text-xs text-ink-muted">{{
                            r.employment?.employeeNumber
                        }}</span>
                        on {{ r.date ?? '—' }}: {{ halves(r.before) }} → {{ halves(r.after) }}
                    </p>
                    <p class="text-xs text-ink-muted">
                        {{ reasonLabels[r.reasonCode] ?? r.reasonCode }} ·
                        {{ statusLabels[r.status] }}<span v-if="r.mine"> · your request</span>
                    </p>
                    <button
                        v-if="r.canDecide && deciding !== r.id"
                        type="button"
                        class="mt-1 text-sm underline"
                        @click="
                            ((deciding = r.id), decisionForm.reset(), decisionForm.clearErrors())
                        "
                    >
                        Decide
                    </button>
                    <form
                        v-if="r.canDecide && deciding === r.id"
                        class="mt-2 space-y-3 rounded-xs border border-control-line p-4"
                        @submit.prevent
                    >
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="decisionForm.confirm" type="checkbox" class="mt-1" />
                            I have checked this correction. Approving applies it now; the month
                            stays locked.
                        </label>
                        <MfaCodeField
                            :id="`decide-mfa-${r.id}`"
                            v-model="decisionForm.mfa_code"
                            class="w-72"
                            :has-mfa-factor="hasMfaFactor"
                            action="Deciding a locked-month correction"
                            :error="decisionForm.errors.mfa_code"
                        />
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="
                                    decisionForm.processing ||
                                    !decisionForm.confirm ||
                                    decisionForm.mfa_code === ''
                                "
                                @click="decide(r.id, 'approve')"
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :disabled="
                                    decisionForm.processing ||
                                    !decisionForm.confirm ||
                                    decisionForm.mfa_code === ''
                                "
                                @click="decide(r.id, 'refuse')"
                            >
                                Refuse
                            </button>
                            <button
                                type="button"
                                class="text-sm underline"
                                @click="deciding = null"
                            >
                                Back
                            </button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>

        <p v-if="!summary.calendarConfigured" class="mt-3 text-sm text-ink">
            The staff calendar is not configured, so no day is shown as a holiday or off day.
        </p>

        <EmptyState
            v-if="summary.rows.length === 0"
            class="mt-6"
            title="No employment in this month"
            description="Nobody was employed during these days."
        />
        <div
            v-else
            class="relative mt-6 overflow-x-auto"
            tabindex="0"
            role="region"
            aria-label="Staff attendance days by state, scrollable"
        >
            <table class="w-full min-w-[44rem] text-left text-sm">
                <caption class="sr-only">
                    Staff attendance days by state
                </caption>
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th
                            v-for="s in states"
                            :key="s.key"
                            scope="col"
                            class="py-2 text-right font-medium"
                        >
                            {{ s.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in summary.rows" :key="row.employment.employmentRecordId">
                        <td class="py-2">
                            {{ row.employment.fullName ?? '—' }}
                            <span class="text-xs text-ink-muted">{{
                                row.employment.employeeNumber
                            }}</span>
                        </td>
                        <td v-for="s in states" :key="s.key" class="py-2 text-right font-mono">
                            {{ days(row.halfDays[s.key]) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-line font-medium">
                        <td class="py-2">All employees</td>
                        <td v-for="s in states" :key="s.key" class="py-2 text-right font-mono">
                            {{ days(summary.totals[s.key]) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
            <RosterPager
                :page="summary.page"
                url="/app/staff-attendance/monthly"
                :params="{ month: summary.month }"
            />
        </div>
    </main>
</template>
