<script setup lang="ts">
import { formatTimeRange } from '../../../datetime';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
 *
 * D-04 (owner, 2026-11-04): "Mark remaining Present" is an explicit
 * action, never a default. It sets ONLY the Students still unmarked and
 * never overwrites a mark already made; submission rules are unchanged.
 */
interface ClassRow {
    timetableEntryId: string;
    sectionCode: string | null;
    classLabel: string | null;
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
    // SIS 6.1 (ATT-09): the School's date; a later date offers no register.
    today: string;
    futureDate: boolean;
    selectedTimetableEntryId: string | null;
    classes: ClassRow[];
    roster: RosterRow[];
    rosterError: string | null;
    statuses: string[];
    // TCH.4: "My Attendance" reuses this page under its own URL.
    baseUrl?: string;
}

const props = withDefaults(defineProps<Props>(), { baseUrl: '/app/attendance' });

const attendanceDate = ref(props.attendanceDate);
const selected = ref(props.selectedTimetableEntryId ?? '');

// Every Student starts UNMARKED. A default would quietly turn an
// incomplete register into a complete-looking one.
const marks = ref<Record<string, string>>({});
const PRESENT = 'present';
const markedPresentNotice = ref('');

watch(
    () => props.roster,
    () => {
        marks.value = {};
        markedPresentNotice.value = '';
    },
);

function reload(): void {
    router.get(
        `${props.baseUrl}/take`,
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

const recordErrors = computed(() =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key, value]) => (key === 'records' || key.startsWith('records.')) && value)
        .map(([, value]) => value as string),
);

function unmarkedCount(): number {
    return props.roster.filter((member) => !marks.value[member.studentEnrollmentId]).length;
}

// D-04: the marker clicks it, typically after marking the absentees.
function markRemainingPresent(): void {
    let count = 0;
    for (const member of props.roster) {
        if (!marks.value[member.studentEnrollmentId]) {
            marks.value[member.studentEnrollmentId] = PRESENT;
            count++;
        }
    }
    markedPresentNotice.value = `${count} Student(s) marked Present. Check the register, then submit.`;
}

function submit(): void {
    form.timetable_entry_id = selected.value;
    form.attendance_date = attendanceDate.value;
    form.records = props.roster.map((member) => ({
        student_enrollment_id: member.studentEnrollmentId,
        status: marks.value[member.studentEnrollmentId],
    }));
    form.post(props.baseUrl);
}
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" :href="baseUrl">← Registers</a>

        <h1 class="mt-2 text-xl font-semibold">Take a register</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Mark every Student, then submit. Registers are submitted complete — there is no partial
            save.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-ink-muted" for="attendance-date">Date</label>
            <input
                id="attendance-date"
                v-model="attendanceDate"
                type="date"
                :max="today"
                :aria-describedby="futureDate ? 'attendance-date-future' : undefined"
                class="input mt-1 w-56"
                @change="reload"
            />
            <p
                v-if="futureDate"
                id="attendance-date-future"
                class="mt-2 text-sm text-danger"
                role="alert"
            >
                Attendance cannot be taken for a future date. Choose today or an earlier date.
            </p>
        </div>

        <h2 class="mt-8 text-sm font-semibold text-ink">Scheduled classes</h2>
        <EmptyState
            v-if="classes.length === 0 && !futureDate"
            class="mt-2"
            title="Nothing scheduled on this date"
            description="Pick another date, or schedule the class in the Timetable first."
        />
        <ul v-else class="mt-2 space-y-2">
            <li v-for="row in classes" :key="row.timetableEntryId">
                <button
                    type="button"
                    class="w-full rounded-xs border px-3 py-2 text-left text-sm"
                    :class="
                        selected === row.timetableEntryId
                            ? 'border-accent-strong bg-graphite-100'
                            : 'border-control-line'
                    "
                    :disabled="row.alreadySubmitted"
                    @click="chooseClass(row.timetableEntryId)"
                >
                    <span class="font-medium"
                        >{{ row.classLabel ?? row.sectionCode }} · {{ row.subjectName }}</span
                    >
                    <span class="text-ink-muted">
                        — {{ row.periodName }} ({{
                            formatTimeRange(row.periodStartTime, row.periodEndTime)
                        }}),
                        {{ row.teacherName }}
                    </span>
                    <span v-if="row.alreadySubmitted" class="ml-2 text-ink-muted">
                        · already submitted
                    </span>
                </button>
            </li>
        </ul>

        <p
            v-if="rosterError"
            class="mt-6 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ rosterError }}
        </p>

        <template v-if="selected && !rosterError">
            <h2 class="mt-8 text-sm font-semibold text-ink">Roster</h2>
            <EmptyState
                v-if="roster.length === 0"
                class="mt-2"
                title="No Student was placed in this Section on this date"
                description="A register cannot be taken for an empty roster."
            />
            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-2">Roll</th>
                        <th class="py-2">Student</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in roster"
                        :key="member.studentEnrollmentId"
                        class="border-b border-line"
                    >
                        <td class="py-2">{{ member.rollNumber }}</td>
                        <td class="py-2">{{ member.fullName }}</td>
                        <td class="py-2">
                            <!-- SIS 6.1: each row's statuses are one named group. -->
                            <fieldset>
                                <legend class="sr-only">Status for {{ member.fullName }}</legend>
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
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="roster.length > 0" class="mt-6 flex flex-wrap items-center gap-4">
                <button
                    v-if="statuses.includes(PRESENT)"
                    type="button"
                    class="btn btn-secondary"
                    data-testid="mark-remaining-present"
                    :disabled="unmarkedCount() === 0 || form.processing"
                    @click="markRemainingPresent"
                >
                    Mark remaining Present
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="unmarkedCount() > 0 || form.processing"
                    @click="submit"
                >
                    Submit register
                </button>
                <span v-if="unmarkedCount() > 0" class="text-sm text-ink-muted">
                    {{ unmarkedCount() }} Student(s) still unmarked.
                </span>
            </div>
            <p class="mt-2 text-sm text-ink-muted" aria-live="polite">{{ markedPresentNotice }}</p>

            <p v-if="form.errors.timetable_entry_id" class="mt-3 text-sm text-danger" role="alert">
                {{ form.errors.timetable_entry_id }}
            </p>
            <!-- LC-1: a refused register line (records.*) used to show nothing. -->
            <p v-if="recordErrors.length" class="mt-3 text-sm text-danger" role="alert">
                {{ recordErrors[0] }}
            </p>
        </template>
    </main>
</template>
