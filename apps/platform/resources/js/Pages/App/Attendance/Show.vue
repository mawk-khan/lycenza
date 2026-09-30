<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

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

function correct(record: RecordRow, newStatus: string): void {
    if (newStatus === record.status) {
        return;
    }
    correcting.value = record.id;
    router.post(
        `${props.baseUrl}/records/${record.id}/correct`,
        { expected_status: record.status, new_status: newStatus },
        { onFinish: () => (correcting.value = null) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="baseUrl">← Registers</a>

        <h1 class="mt-2 text-xl font-semibold">
            {{ session.sectionCode }} · {{ session.subjectName }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ DAY_NAMES[session.dayOfWeek] }} {{ session.attendanceDate }} ·
            {{ session.periodName }} ({{ session.periodStartTime }}–{{ session.periodEndTime }}) ·
            {{ session.teacherName }}
        </p>
        <p class="mt-1 text-xs text-slate-400">
            {{ session.academicYearName }} · {{ session.campusName }} ·
            {{ session.gradeLevelName }} · submitted {{ session.submittedAt }}
        </p>

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
                    <td class="py-2">{{ record.fullName }}</td>
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
                            :disabled="status === record.status || correcting === record.id"
                            @click="correct(record, status)"
                        >
                            {{ status }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
