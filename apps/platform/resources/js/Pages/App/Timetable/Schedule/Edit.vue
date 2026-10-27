<script setup lang="ts">
import { formatTimeRange } from '../../../../datetime';
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, watch, nextTick } from 'vue';

/**
 * LC-2: correct a scheduled class -- its teacher, room, period and day. The
 * Subject Offering and Section are what the class is; for a different one,
 * schedule a new class. Collisions are checked within the class's own
 * academic year; a refused change is explained in the refusal banner and
 * nothing is saved.
 */
interface Props {
    entry: {
        id: string;
        status: 'active' | 'inactive';
        dayOfWeek: number;
        subjectCode: string | null;
        subjectName: string | null;
        classLabel: string | null;
        sectionName: string | null;
        academicYearName: string | null;
        teacherId: string;
        teacherName: string | null;
        roomId: string | null;
        roomCode: string | null;
        roomName: string | null;
        periodId: string;
        periodCode: string | null;
        periodStartTime: string | null;
        periodEndTime: string | null;
    };
}

interface TeacherOption {
    id: string;
    fullName: string;
}

interface RoomOption {
    id: string;
    code: string;
    name: string;
}

interface PeriodOption {
    id: string;
    code: string;
    name: string;
    startTime: string;
    endTime: string;
}

const props = defineProps<Props>();

const DAYS = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

const form = useForm({
    teacher_id: props.entry.teacherId,
    room_id: props.entry.roomId ?? '',
    period_id: props.entry.periodId,
    day_of_week: props.entry.dayOfWeek,
});

function focusSoon(id: string): void {
    nextTick(() => document.getElementById(id)?.focus());
}

function makePicker<T extends { id: string }>(endpoint: string, initialLabel: string) {
    const query = ref('');
    const results = ref<T[]>([]);
    const selectedLabel = ref(initialLabel);
    let debounce: ReturnType<typeof setTimeout> | undefined;

    watch(query, (value) => {
        clearTimeout(debounce);
        if (value.trim() === '') {
            results.value = [];
            return;
        }
        debounce = setTimeout(async () => {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(value)}`);
            const body = response.ok
                ? await response.json().catch(() => ({ data: [] }))
                : { data: [] };
            results.value = body.data as T[];
        }, 250);
    });

    return { query, results, selectedLabel };
}

const teacherPicker = makePicker<TeacherOption>(
    '/app/timetable-schedule/search/teachers',
    props.entry.teacherName ?? '',
);
const roomPicker = makePicker<RoomOption>(
    '/app/timetable-schedule/search/rooms',
    props.entry.roomCode ? `${props.entry.roomCode} — ${props.entry.roomName ?? ''}` : '',
);
const periodPicker = makePicker<PeriodOption>(
    '/app/timetable-schedule/search/schedule-periods',
    props.entry.periodCode
        ? `${props.entry.periodCode} (${props.entry.periodStartTime}–${props.entry.periodEndTime})`
        : '',
);

function selectTeacher(option: TeacherOption): void {
    form.teacher_id = option.id;
    teacherPicker.selectedLabel.value = option.fullName;
    teacherPicker.query.value = '';
    teacherPicker.results.value = [];
    focusSoon('teacher-change');
}

function selectRoom(option: RoomOption): void {
    form.room_id = option.id;
    roomPicker.selectedLabel.value = `${option.code} — ${option.name}`;
    roomPicker.query.value = '';
    roomPicker.results.value = [];
    focusSoon('room-change');
}

function selectPeriod(option: PeriodOption): void {
    form.period_id = option.id;
    periodPicker.selectedLabel.value = `${option.code} (${option.startTime}–${option.endTime})`;
    periodPicker.query.value = '';
    periodPicker.results.value = [];
    focusSoon('period-change');
}

function submit(): void {
    form.transform((data) => ({ ...data, room_id: data.room_id || null })).patch(
        `/app/timetable-schedule/${props.entry.id}`,
    );
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/timetable-schedule">← Timetable schedule</a>
        <h1 class="mt-2 text-xl font-semibold">Edit class</h1>

        <dl class="mt-4 rounded border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
            <div class="flex gap-2">
                <dt class="text-slate-500">Subject</dt>
                <dd>{{ entry.subjectCode }} — {{ entry.subjectName }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="text-slate-500">Class</dt>
                <dd>{{ entry.classLabel ?? entry.sectionName }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="text-slate-500">Academic year</dt>
                <dd>{{ entry.academicYearName }}</dd>
            </div>
        </dl>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="teacher">Teacher</label>
                <div
                    v-if="teacherPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ teacherPicker.selectedLabel.value }}</span>
                    <button
                        id="teacher-change"
                        type="button"
                        class="text-slate-500 underline hover:text-slate-700"
                        @click="
                            form.teacher_id = '';
                            teacherPicker.selectedLabel.value = '';
                            focusSoon('teacher');
                        "
                    >
                        Change<span class="sr-only"> teacher</span>
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="teacher"
                    v-model="teacherPicker.query.value"
                    placeholder="Search by name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="teacherPicker.results.value"
                    :option-key="(o) => o.id"
                    :error="form.errors.teacher_id"
                    @select="selectTeacher"
                >
                    <template #option="{ option: option }">
                        {{ option.fullName }}
                    </template>
                </SearchCombobox>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="room">Room (optional)</label>
                <div
                    v-if="roomPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ roomPicker.selectedLabel.value }}</span>
                    <button
                        id="room-change"
                        type="button"
                        class="text-slate-500 underline hover:text-slate-700"
                        @click="
                            form.room_id = '';
                            roomPicker.selectedLabel.value = '';
                            focusSoon('room');
                        "
                    >
                        Change<span class="sr-only"> room</span>
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="room"
                    v-model="roomPicker.query.value"
                    placeholder="Search by Room code or name (leave empty for no room)"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="roomPicker.results.value"
                    :option-key="(o) => o.id"
                    :error="form.errors.room_id"
                    @select="selectRoom"
                >
                    <template #option="{ option: option }">
                        {{ option.code }} — {{ option.name }}
                    </template>
                </SearchCombobox>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="period">Period</label>
                <div
                    v-if="periodPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ periodPicker.selectedLabel.value }}</span>
                    <button
                        id="period-change"
                        type="button"
                        class="text-slate-500 underline hover:text-slate-700"
                        @click="
                            form.period_id = '';
                            periodPicker.selectedLabel.value = '';
                            focusSoon('period');
                        "
                    >
                        Change<span class="sr-only"> period</span>
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="period"
                    v-model="periodPicker.query.value"
                    placeholder="Search by Period code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="periodPicker.results.value"
                    :option-key="(o) => o.id"
                    :error="form.errors.period_id"
                    @select="selectPeriod"
                >
                    <template #option="{ option: option }">
                        {{ option.code }} ({{ formatTimeRange(option.startTime, option.endTime) }})
                    </template>
                </SearchCombobox>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="day_of_week">Day</label>
                <select
                    id="day_of_week"
                    v-model.number="form.day_of_week"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option v-for="day in DAYS" :key="day.value" :value="day.value">
                        {{ day.label }}
                    </option>
                </select>
                <p v-if="form.errors.day_of_week" class="mt-1 text-sm text-red-600">
                    {{ form.errors.day_of_week }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !form.teacher_id || !form.period_id"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save class' }}
                </button>
                <a class="text-sm underline" href="/app/timetable-schedule">Cancel</a>
            </div>
        </form>
    </main>
</template>
