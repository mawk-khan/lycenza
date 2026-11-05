<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import RosterPager from '../../../Components/StaffAttendance/RosterPager.vue';
import {
    employmentName,
    halfLabel,
    halfOpen,
    reasonLabels,
    summaryLabels,
    useAttendanceError,
    type AttendanceDay,
    type EmploymentLabel,
    type HalfStatus,
} from '../../../staffAttendance';

/**
 * HRX.3 — the daily staff attendance register (administration).
 *
 * Each half is recorded present or absent, or left unrecorded. Leave,
 * holidays and weekly offs come from Leave and the staff calendar and are
 * shown, never stored here. A saved row changes only by correction, with a
 * reason from a closed list. No clock times, no notes, nothing medical.
 */
interface Row {
    employment: EmploymentLabel;
    day: AttendanceDay;
}

interface Props {
    register: {
        date: string;
        calendarConfigured: boolean;
        calendar: { firstHalf: string; secondHalf: string } | null;
        rows: Row[];
        page: { page: number; perPage: number; total: number; lastPage: number };
    };
    today: string;
    reasons: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const date = ref(props.register.date);
watch(date, () => {
    if (date.value) {
        router.get('/app/staff-attendance', { date: date.value }, { preserveState: false });
    }
});

/** Unsaved marks per employment: [first half, second half]. */
const marks = reactive<Record<string, [HalfStatus, HalfStatus]>>({});
for (const row of props.register.rows) {
    marks[row.employment.employmentRecordId] = [null, null];
}

function recordable(row: Row): boolean {
    return (
        props.canManage &&
        row.day.record === null &&
        row.employment.recordable &&
        (halfOpen(row.day.firstHalf) || halfOpen(row.day.secondHalf))
    );
}

const marked = computed(() =>
    props.register.rows.filter((row) => {
        const m = marks[row.employment.employmentRecordId];
        return recordable(row) && m !== undefined && (m[0] !== null || m[1] !== null);
    }),
);

const saving = ref(false);

function saveOne(row: Row): void {
    const m = marks[row.employment.employmentRecordId] ?? [null, null];
    saving.value = true;
    router.post(
        '/app/staff-attendance/records',
        {
            employment_record_id: row.employment.employmentRecordId,
            date: props.register.date,
            first_half: m[0],
            second_half: m[1],
        },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

function saveAll(): void {
    saving.value = true;
    router.post(
        '/app/staff-attendance/register',
        {
            date: props.register.date,
            items: marked.value.map((row) => {
                const m = marks[row.employment.employmentRecordId] ?? [null, null];
                return {
                    employment_record_id: row.employment.employmentRecordId,
                    first_half: m[0],
                    second_half: m[1],
                };
            }),
        },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

const correcting = ref<Row | null>(null);
const correction = useForm<{
    expected_version: number;
    first_half: HalfStatus;
    second_half: HalfStatus;
    reason_code: string;
}>({ expected_version: 1, first_half: null, second_half: null, reason_code: '' });

function startCorrection(row: Row): void {
    correcting.value = row;
    correction.expected_version = row.day.record?.version ?? 1;
    correction.first_half = row.day.firstHalf.recorded;
    correction.second_half = row.day.secondHalf.recorded;
    correction.reason_code = '';
    correction.clearErrors();
}

function submitCorrection(): void {
    const record = correcting.value?.day.record;
    if (!record) {
        return;
    }
    correction.post(`/app/staff-attendance/records/${record.id}/corrections`, {
        preserveScroll: true,
        onSuccess: () => (correcting.value = null),
    });
}

const attendanceError = useAttendanceError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Staff attendance</h1>
        <p class="mt-1 text-sm text-ink-muted">
            The daily register. Each half is present, absent or not recorded. Leave, holidays and
            weekly offs come from Leave and the staff calendar.
        </p>
        <p class="mt-1 text-sm">
            <a class="underline" href="/app/staff-attendance/monthly">Month summary</a>
        </p>
        <p
            v-if="attendanceError"
            role="alert"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ attendanceError }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="text-sm text-ink-muted" for="register-date">Date</label>
            <input
                id="register-date"
                v-model="date"
                type="date"
                :max="today"
                class="input w-auto"
            />
            <span v-if="register.calendar" class="text-sm text-ink-muted">
                Calendar: first half {{ register.calendar.firstHalf }}, second half
                {{ register.calendar.secondHalf }}
            </span>
        </div>
        <p
            v-if="!register.calendarConfigured"
            class="mt-3 rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm text-ink"
        >
            The staff working week is not configured yet (Leave → Configuration). Attendance cannot
            be recorded until it is.
        </p>

        <EmptyState
            v-if="register.rows.length === 0"
            class="mt-8"
            title="No employments on this date"
            description="Nobody in this School was employed on the chosen date."
        />
        <!-- HR.16F: the table scrolls inside its own region on narrow screens; the page never does. -->
        <div v-else class="relative mt-6 overflow-x-auto">
            <table class="w-full min-w-[40rem] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-2">Employee</th>
                        <th class="py-2">First half</th>
                        <th class="py-2">Second half</th>
                        <th class="py-2">Day</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in register.rows"
                        :key="row.employment.employmentRecordId"
                        class="border-b border-line align-top"
                    >
                        <td class="py-2">
                            <a
                                class="underline"
                                :href="`/app/staff-attendance/history?employment_record_id=${row.employment.employmentRecordId}&to=${register.date}`"
                                >{{ employmentName(row.employment) }}</a
                            >
                        </td>
                        <td
                            v-for="(half, index) in [row.day.firstHalf, row.day.secondHalf]"
                            :key="index"
                            class="py-2"
                        >
                            <select
                                v-if="recordable(row) && halfOpen(half)"
                                v-model="marks[row.employment.employmentRecordId]![index]"
                                class="input w-auto"
                                :aria-label="`${employmentName(row.employment)} ${index === 0 ? 'first' : 'second'} half`"
                            >
                                <option :value="null">Not recorded</option>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                            </select>
                            <span v-else>{{ halfLabel(half) }}</span>
                        </td>
                        <td class="py-2">
                            {{ summaryLabels[row.day.summary] ?? row.day.summary }}
                        </td>
                        <td class="space-x-3 py-2 text-right">
                            <button
                                v-if="recordable(row)"
                                type="button"
                                class="underline"
                                :disabled="saving"
                                @click="saveOne(row)"
                            >
                                Save
                            </button>
                            <button
                                v-if="canManage && row.day.record"
                                type="button"
                                class="underline"
                                @click="startCorrection(row)"
                            >
                                Correct
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <RosterPager
            v-if="register.page.total > 0"
            :page="register.page"
            url="/app/staff-attendance"
            :params="{ date: register.date }"
        />

        <div v-if="canManage && register.rows.length > 0" class="mt-4 flex items-center gap-3">
            <button
                type="button"
                class="btn btn-primary"
                :disabled="saving || marked.length === 0"
                @click="saveAll"
            >
                Save register ({{ marked.length }})
            </button>
            <span class="text-sm text-ink-muted"
                >All marked rows are saved together, or none are. Saved rows change only by
                correction.</span
            >
        </div>

        <form
            v-if="correcting"
            class="mt-6 rounded-xs border border-control-line p-4"
            @submit.prevent="submitCorrection"
        >
            <h2 class="text-sm font-semibold">
                Correct {{ employmentName(correcting.employment) }} on {{ register.date }}
            </h2>
            <p class="mt-1 text-xs text-ink-muted">
                The previous values are kept as correction history. A half on approved leave cannot
                be set to present or absent; cancel the leave first.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <label class="text-sm text-ink-muted" for="correct-first">First half</label>
                <select id="correct-first" v-model="correction.first_half" class="input w-auto">
                    <option :value="null">Not recorded</option>
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                </select>
                <label class="text-sm text-ink-muted" for="correct-second">Second half</label>
                <select id="correct-second" v-model="correction.second_half" class="input w-auto">
                    <option :value="null">Not recorded</option>
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                </select>
                <label class="text-sm text-ink-muted" for="correct-reason">Reason</label>
                <select id="correct-reason" v-model="correction.reason_code" class="input w-auto">
                    <option value="">Select a reason</option>
                    <option v-for="r in reasons" :key="r" :value="r">
                        {{ reasonLabels[r] ?? r }}
                    </option>
                </select>
                <button type="submit" class="btn btn-primary" :disabled="correction.processing">
                    Save correction
                </button>
                <button type="button" class="text-sm underline" @click="correcting = null">
                    Back
                </button>
            </div>
            <p v-if="correction.errors.reason_code" class="mt-2 text-sm text-danger">
                {{ correction.errors.reason_code }}
            </p>
        </form>
    </main>
</template>
