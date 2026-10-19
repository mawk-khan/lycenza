<script setup lang="ts">
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, watch, nextTick } from 'vue';

interface SubjectOfferingOption {
    id: string;
    subjectCode: string;
    subjectName: string;
    gradeLevelName: string;
    campusName: string;
    academicYearName: string;
}

interface SectionOption {
    id: string;
    code: string;
    name: string;
    label: string;
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
    subject_offering_id: '',
    section_id: '',
    teacher_id: '',
    room_id: '',
    period_id: '',
    day_of_week: 1,
});

// UAT-C4 (UAT-A11Y-01): a pick swaps the search box for the chosen value and
// its Change button, and Change swaps it back -- keep keyboard focus on what
// replaced the focused control.
function focusSoon(id: string): void {
    nextTick(() => document.getElementById(id)?.focus());
}

function makePicker<T extends { id: string }>(endpoint: string) {
    const query = ref('');
    const results = ref<T[]>([]);
    const selectedLabel = ref('');
    let debounce: ReturnType<typeof setTimeout> | undefined;

    watch(query, (value) => {
        clearTimeout(debounce);
        // UAT-C4: a pick clears the query; an empty search is no search.
        if (value.trim() === '') {
            results.value = [];
            return;
        }
        debounce = setTimeout(async () => {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(value)}`);
            if (!response.ok) {
                results.value = [];
                return;
            }
            // UAT-C4: a refused or non-JSON answer is simply no results.
            const body = response.ok
                ? await response.json().catch(() => ({ data: [] }))
                : { data: [] };
            results.value = body.data as T[];
        }, 250);
    });

    return { query, results, selectedLabel };
}

const offeringPicker = makePicker<SubjectOfferingOption>(
    '/app/timetable-schedule/search/subject-offerings',
);
const sectionPicker = makePicker<SectionOption>('/app/timetable-schedule/search/sections');
const teacherPicker = makePicker<TeacherOption>('/app/timetable-schedule/search/teachers');
const roomPicker = makePicker<RoomOption>('/app/timetable-schedule/search/rooms');
const periodPicker = makePicker<PeriodOption>('/app/timetable-schedule/search/schedule-periods');

function selectOffering(option: SubjectOfferingOption): void {
    form.subject_offering_id = option.id;
    offeringPicker.selectedLabel.value = `${option.subjectCode} — ${option.subjectName} (${option.gradeLevelName}, ${option.academicYearName})`;
    offeringPicker.query.value = '';
    offeringPicker.results.value = [];
    focusSoon('offering-change');
}

function selectSection(option: SectionOption): void {
    form.section_id = option.id;
    sectionPicker.selectedLabel.value = option.label;
    sectionPicker.query.value = '';
    sectionPicker.results.value = [];
    focusSoon('section-change');
}

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

function clearRoom(): void {
    form.room_id = '';
    roomPicker.selectedLabel.value = '';
}

function selectPeriod(option: PeriodOption): void {
    form.period_id = option.id;
    periodPicker.selectedLabel.value = `${option.code} (${option.startTime}–${option.endTime})`;
    periodPicker.query.value = '';
    periodPicker.results.value = [];
    focusSoon('period-change');
}

function submit(): void {
    form.post('/app/timetable-schedule');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/timetable-schedule">← Timetable schedule</a>
        <h1 class="mt-2 text-xl font-semibold">Schedule a class</h1>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="offering">Subject Offering</label>
                <div
                    v-if="offeringPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ offeringPicker.selectedLabel.value }}</span>
                    <button
                        id="offering-change"
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.subject_offering_id = '';
                            offeringPicker.selectedLabel.value = '';
                            focusSoon('offering');
                        "
                    >
                        Change
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="offering"
                    v-model="offeringPicker.query.value"
                    placeholder="Search by Subject code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="offeringPicker.results.value"
                    :option-key="(o) => o.id"
                    :error="form.errors.subject_offering_id"
                    @select="selectOffering"
                >
                    <template #option="{ option: option }">
                        {{ option.subjectCode }} — {{ option.subjectName }} ({{
                            option.gradeLevelName
                        }}, {{ option.academicYearName }})
                    </template>
                </SearchCombobox>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="section">Section</label>
                <div
                    v-if="sectionPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ sectionPicker.selectedLabel.value }}</span>
                    <button
                        id="section-change"
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.section_id = '';
                            sectionPicker.selectedLabel.value = '';
                            focusSoon('section');
                        "
                    >
                        Change
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="section"
                    v-model="sectionPicker.query.value"
                    placeholder="Search by Section code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="sectionPicker.results.value"
                    :option-key="(o) => o.id"
                    :error="form.errors.section_id"
                    @select="selectSection"
                >
                    <template #option="{ option: option }">
                        {{ option.label }}
                    </template>
                </SearchCombobox>
            </div>

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
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.teacher_id = '';
                            teacherPicker.selectedLabel.value = '';
                            focusSoon('teacher');
                        "
                    >
                        Change
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
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            clearRoom();
                            focusSoon('room');
                        "
                    >
                        Change
                    </button>
                </div>
                <SearchCombobox
                    v-else
                    id="room"
                    v-model="roomPicker.query.value"
                    placeholder="Search by Room code or name"
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
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.period_id = '';
                            periodPicker.selectedLabel.value = '';
                            focusSoon('period');
                        "
                    >
                        Change
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
                        {{ option.code }} ({{ option.startTime }}–{{ option.endTime }})
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

            <button
                type="submit"
                :disabled="
                    form.processing ||
                    !form.subject_offering_id ||
                    !form.section_id ||
                    !form.teacher_id ||
                    !form.period_id
                "
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Scheduling…' : 'Schedule class' }}
            </button>
        </form>
    </main>
</template>
