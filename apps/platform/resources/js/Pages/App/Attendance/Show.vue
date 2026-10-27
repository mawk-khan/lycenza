<script setup lang="ts">
import { formatCalendarDate, formatDateTime } from '../../../datetime';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';

/**
 * One submitted register.
 *
 * Sourcing rules, mirroring the API presenter exactly:
 *  - class identity comes from the AttendanceSession's IMMUTABLE
 *    snapshot (Section, Subject, teacher, Period identity, and the
 *    historical wall-clock times);
 *  - names/codes resolve through those snapshotted identities to the
 *    referenced entity's CURRENT row, so a later rename propagates
 *    correctly under an unchanged historical identity;
 *  - periodName is the CURRENT label, while periodStartTime/EndTime are
 *    frozen historical values -- these two can legitimately disagree;
 *  - timetableEntryId is provenance only and is never dereferenced.
 */
interface RecordRow {
    id: string;
    studentId: string | null;
    rollNumber: string | null;
    fullName: string | null;
    status: string;
    correctedAt: string | null;
}

interface SessionDetail {
    id: string;
    attendanceDate: string;
    dayOfWeek: number;
    academicYearName: string | null;
    campusName: string | null;
    gradeLevelName: string | null;
    sectionCode: string | null;
    sectionName: string | null;
    // SIS 6.1: "Grade 6 · A", the class as named everywhere else.
    classLabel: string | null;
    subjectName: string | null;
    subjectCode: string | null;
    teacherName: string | null;
    periodName: string | null;
    periodCode: string | null;
    periodStartTime: string;
    periodEndTime: string;
    timetableEntryId: string;
    submittedAt: string | null;
    records: RecordRow[];
}

interface Props {
    session: SessionDetail;
    statuses: string[];
    canManage: boolean;
    // ORG.8E (ADR 0076 §63): the closed reasons a correction must name.
    correctionReasons: string[];
    // TCH.4: "My Attendance" reuses this page under its own URL.
    baseUrl?: string;
}

const props = withDefaults(defineProps<Props>(), { baseUrl: '/app/attendance' });

const DAY_NAMES = [
    '',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

// The status the page currently DISPLAYS is sent as expected_status, so
// a stale tab is refused rather than silently overwriting a colleague's
// correction.
const correcting = ref<string | null>(null);

// LC-1: a refused correction (a colleague changed the record first, or the
// date is outside your teaching assignment) used to vanish -- the error came
// back on `new_status`/`expected_status` and nothing rendered it.
const page = usePage<{ errors?: Record<string, string> }>();
const correctionError = computed(
    () =>
        page.props.errors?.new_status ??
        page.props.errors?.expected_status ??
        page.props.errors?.reason_code ??
        null,
);

// ORG.8E: changing a recorded status is a correction and names its reason.
const REASON_LABELS: Record<string, string> = {
    entered_in_error: 'Entered in error',
    late_information: 'Information received after the register',
    administrative_review: 'Administrative review',
};
const reasonCode = ref('');

// SIS 6.1 (ATT-06): a correction is summarized and confirmed before it is
// sent. Operator protection only: the service still re-checks the status the
// page displayed (expected_status) and refuses a stale one.
const pending = ref<{ record: RecordRow; newStatus: string } | null>(null);
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);

function statusLabel(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function correct(record: RecordRow, newStatus: string): void {
    if (newStatus === record.status || reasonCode.value === '') {
        return;
    }
    pending.value = { record, newStatus };
    confirmDialog.value?.open();
}

function confirmCorrection(): void {
    const target = pending.value;
    if (target === null) {
        return;
    }
    correcting.value = target.record.id;
    router.post(
        `${props.baseUrl}/records/${target.record.id}/correct`,
        {
            expected_status: target.record.status,
            new_status: target.newStatus,
            reason_code: reasonCode.value,
        },
        {
            onFinish: () => {
                correcting.value = null;
                pending.value = null;
            },
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="baseUrl">← Registers</a>

        <h1 class="mt-2 text-xl font-semibold">
            {{ session.classLabel ?? session.sectionCode }} · {{ session.subjectName }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ DAY_NAMES[session.dayOfWeek] }} {{ formatCalendarDate(session.attendanceDate) }} ·
            {{ session.periodName }} ({{ session.periodStartTime }}–{{ session.periodEndTime }}) ·
            {{ session.teacherName }}
        </p>
        <p class="mt-1 text-xs text-slate-400">
            {{ session.academicYearName }} · {{ session.campusName }} ·
            {{ session.gradeLevelName }} · submitted {{ formatDateTime(session.submittedAt) }}
        </p>

        <p
            v-if="correctionError"
            class="mt-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900"
            role="alert"
        >
            <span class="font-semibold">Not corrected:</span> {{ correctionError }} Reload the page
            to see the register as it is now.
        </p>

        <div v-if="canManage" class="mt-6">
            <label class="block text-sm text-slate-600" for="correction-reason">
                Reason for correction
            </label>
            <select
                id="correction-reason"
                v-model="reasonCode"
                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-72"
            >
                <option value="" disabled>Choose a reason before correcting</option>
                <option v-for="r in correctionReasons" :key="r" :value="r">
                    {{ REASON_LABELS[r] ?? r }}
                </option>
            </select>
            <p class="mt-1 text-xs text-slate-500">
                Changing a recorded status is kept with this reason and your name.
            </p>
        </div>

        <table class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Roll</th>
                    <th class="py-2">Student</th>
                    <th class="py-2">Status</th>
                    <th v-if="canManage" class="py-2">Correct to</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="record in session.records"
                    :key="record.id"
                    class="border-b border-slate-100"
                >
                    <td class="py-2">{{ record.rollNumber }}</td>
                    <td class="py-2">
                        <a
                            v-if="record.studentId && baseUrl === '/app/attendance'"
                            class="underline"
                            :href="`/app/attendance/students/${record.studentId}`"
                            >{{ record.fullName }}</a
                        >
                        <template v-else>{{ record.fullName }}</template>
                    </td>
                    <td class="py-2 capitalize">
                        {{ record.status }}
                        <span v-if="record.correctedAt" class="ml-1 text-xs text-slate-400">
                            (corrected)
                        </span>
                    </td>
                    <td v-if="canManage" class="py-2">
                        <button
                            v-for="status in statuses"
                            :key="status"
                            type="button"
                            class="mr-2 rounded border border-slate-300 px-2 py-1 text-xs capitalize disabled:opacity-40"
                            :disabled="
                                status === record.status ||
                                correcting === record.id ||
                                reasonCode === ''
                            "
                            :aria-label="`Correct ${record.fullName ?? 'this Student'} to ${status}`"
                            @click="correct(record, status)"
                        >
                            {{ status }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <ConfirmDialog
            ref="confirmDialog"
            title="Correct this attendance mark?"
            confirm-label="Correct the mark"
            tone="neutral"
            @confirm="confirmCorrection"
        >
            <p v-if="pending">
                <strong>{{ pending.record.fullName }}</strong>
                <span v-if="pending.record.rollNumber"> (roll {{ pending.record.rollNumber }})</span
                >, {{ session.classLabel ?? session.sectionCode }} · {{ session.subjectName }},
                {{ formatCalendarDate(session.attendanceDate) }} · {{ session.periodName }}:
                <strong>{{ statusLabel(pending.record.status) }}</strong> →
                <strong>{{ statusLabel(pending.newStatus) }}</strong
                >.
            </p>
            <p class="mt-2">
                Reason: {{ REASON_LABELS[reasonCode] ?? reasonCode }}. The correction is kept with
                this reason and your name.
            </p>
        </ConfirmDialog>
    </main>
</template>
