<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * The marking screen: pick a date, pick a scheduled class, mark EVERY
 * Student, submit.
 *
 * Class selection reads the CURRENT weekly timetable -- correct here,
 * and only here. The roster shown is informational and may go stale;
 * the server re-derives it under locks and rejects the whole register
 * if the submitted set is not exactly the authoritative roster. There
 * is deliberately no partial/draft save and no default-to-present.
 */
interface ClassRow {
    timetableEntryId: string;
    sectionCode: string | null;
    subjectName: string | null;
    teacherName: string | null;
    periodName: string | null;
    periodStartTime: string | null;
    periodEndTime: string | null;
    alreadySubmitted: boolean;
}

interface RosterRow {
    studentEnrollmentId: string;
    studentId: string;
    rollNumber: string;
    fullName: string;
}

interface Props {
    attendanceDate: string;
    selectedTimetableEntryId: string | null;
    classes: ClassRow[];
    roster: RosterRow[];
    rosterError: string | null;
    statuses: string[];
}

const props = defineProps<Props>();

const attendanceDate = ref(props.attendanceDate);
const selected = ref(props.selectedTimetableEntryId ?? '');

// Every Student starts UNMARKED. A default would quietly turn an
// incomplete register into a complete-looking one.
const marks = ref<Record<string, string>>({});

watch(
    () => props.roster,
    () => {
        marks.value = {};
    },
);

function reload(): void {
    router.get(
        '/app/attendance/take',
        {
            attendance_date: attendanceDate.value,
            timetable_entry_id: selected.value || undefined,
        },
        { preserveState: false, replace: true },
    );
}

function chooseClass(timetableEntryId: string): void {
    selected.value = timetableEntryId;
    reload();
}

const form = useForm({
    timetable_entry_id: '',
    attendance_date: '',
    records: [] as { student_enrollment_id: string; status: string }[],
});

function unmarkedCount(): number {
    return props.roster.filter((member) => !marks.value[member.studentEnrollmentId]).length;
}

function submit(): void {
    form.timetable_entry_id = selected.value;
    form.attendance_date = attendanceDate.value;
    form.records = props.roster.map((member) => ({
        student_enrollment_id: member.studentEnrollmentId,
        status: marks.value[member.studentEnrollmentId],
    }));
    form.post('/app/attendance');
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/attendance">← Registers</a>

        <h1 class="mt-2 text-xl font-semibold">Take a register</h1>
        <p class="mt-1 text-sm text-slate-500">
            Mark every Student, then submit. Registers are submitted complete — there is no partial
            save.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="attendance-date">Date</label>
            <input
                id="attendance-date"
                v-model="attendanceDate"
                type="date"
                class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                @change="reload"
            />
        </div>

        <h2 class="mt-8 text-sm font-semibold text-slate-700">Scheduled classes</h2>
        <EmptyState
            v-if="classes.length === 0"
            class="mt-2"
            title="Nothing scheduled on this date"
            description="Pick another date, or schedule the class in the Timetable first."
        />
        <ul v-else class="mt-2 space-y-2">
            <li v-for="row in classes" :key="row.timetableEntryId">
                <button
                    type="button"
                    class="w-full rounded border px-3 py-2 text-left text-sm"
                    :class="
                        selected === row.timetableEntryId
                            ? 'border-slate-900 bg-slate-50'
                            : 'border-slate-300'
                    "
                    :disabled="row.alreadySubmitted"
                    @click="chooseClass(row.timetableEntryId)"
                >
                    <span class="font-medium">{{ row.sectionCode }} · {{ row.subjectName }}</span>
                    <span class="text-slate-500">
                        — {{ row.periodName }} ({{ row.periodStartTime }}–{{ row.periodEndTime }}),
                        {{ row.teacherName }}
                    </span>
                    <span v-if="row.alreadySubmitted" class="ml-2 text-slate-500">
                        · already submitted
                    </span>
                </button>
            </li>
        </ul>

        <p
            v-if="rosterError"
            class="mt-6 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ rosterError }}
        </p>

        <template v-if="selected && !rosterError">
            <h2 class="mt-8 text-sm font-semibold text-slate-700">Roster</h2>
            <EmptyState
                v-if="roster.length === 0"
                class="mt-2"
                title="No Student was placed in this Section on this date"
                description="A register cannot be taken for an empty roster."
            />
            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Roll</th>
                        <th class="py-2">Student</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in roster"
                        :key="member.studentEnrollmentId"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ member.rollNumber }}</td>
                        <td class="py-2">{{ member.fullName }}</td>
                        <td class="py-2">
                            <label
                                v-for="status in statuses"
                                :key="status"
                                class="mr-3 inline-flex items-center gap-1"
                            >
                                <input
                                    v-model="marks[member.studentEnrollmentId]"
                                    type="radio"
                                    :name="`status-${member.studentEnrollmentId}`"
                                    :value="status"
                                />
                                <span class="capitalize">{{ status }}</span>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="roster.length > 0" class="mt-6 flex items-center gap-4">
                <button
                    type="button"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="unmarkedCount() > 0 || form.processing"
                    @click="submit"
                >
                    Submit register
                </button>
                <span v-if="unmarkedCount() > 0" class="text-sm text-slate-500">
                    {{ unmarkedCount() }} Student(s) still unmarked.
                </span>
            </div>

            <p v-if="form.errors.timetable_entry_id" class="mt-3 text-sm text-red-700">
                {{ form.errors.timetable_entry_id }}
            </p>
        </template>
    </main>
</template>
