<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

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

function makePicker<T extends { id: string }>(endpoint: string) {
    const query = ref('');
    const results = ref<T[]>([]);
    const selectedLabel = ref('');
    let debounce: ReturnType<typeof setTimeout> | undefined;

    watch(query, (value) => {
        clearTimeout(debounce);
        debounce = setTimeout(async () => {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(value)}`);
            if (!response.ok) {
                results.value = [];
                return;
            }
            const body = await response.json();
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
const periodPicker = makePicker<PeriodOption>('/app/timetable-schedule/search/periods');

function selectOffering(option: SubjectOfferingOption): void {
    form.subject_offering_id = option.id;
    offeringPicker.selectedLabel.value = `${option.subjectCode} — ${option.subjectName} (${option.gradeLevelName}, ${option.academicYearName})`;
    offeringPicker.query.value = '';
    offeringPicker.results.value = [];
}

function selectSection(option: SectionOption): void {
    form.section_id = option.id;
    sectionPicker.selectedLabel.value = `${option.code} — ${option.name}`;
    sectionPicker.query.value = '';
    sectionPicker.results.value = [];
}

function selectTeacher(option: TeacherOption): void {
    form.teacher_id = option.id;
    teacherPicker.selectedLabel.value = option.fullName;
    teacherPicker.query.value = '';
    teacherPicker.results.value = [];
}

function selectRoom(option: RoomOption): void {
    form.room_id = option.id;
    roomPicker.selectedLabel.value = `${option.code} — ${option.name}`;
    roomPicker.query.value = '';
    roomPicker.results.value = [];
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
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.subject_offering_id = '';
                            offeringPicker.selectedLabel.value = '';
                        "
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="offering"
                    v-model="offeringPicker.query.value"
                    type="text"
                    placeholder="Search by Subject code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="offeringPicker.results.value.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="option in offeringPicker.results.value"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="selectOffering(option)"
                    >
                        {{ option.subjectCode }} — {{ option.subjectName }} ({{
                            option.gradeLevelName
                        }}, {{ option.academicYearName }})
                    </li>
                </ul>
                <p v-if="form.errors.subject_offering_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.subject_offering_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="section">Section</label>
                <div
                    v-if="sectionPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ sectionPicker.selectedLabel.value }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.section_id = '';
                            sectionPicker.selectedLabel.value = '';
                        "
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="section"
                    v-model="sectionPicker.query.value"
                    type="text"
                    placeholder="Search by Section code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="sectionPicker.results.value.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="option in sectionPicker.results.value"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="selectSection(option)"
                    >
                        {{ option.code }} — {{ option.name }}
                    </li>
                </ul>
                <p v-if="form.errors.section_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.section_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="teacher">Teacher</label>
                <div
                    v-if="teacherPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ teacherPicker.selectedLabel.value }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.teacher_id = '';
                            teacherPicker.selectedLabel.value = '';
                        "
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="teacher"
                    v-model="teacherPicker.query.value"
                    type="text"
                    placeholder="Search by name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="teacherPicker.results.value.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="option in teacherPicker.results.value"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="selectTeacher(option)"
                    >
                        {{ option.fullName }}
                    </li>
                </ul>
                <p v-if="form.errors.teacher_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.teacher_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="room">Room (optional)</label>
                <div
                    v-if="roomPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ roomPicker.selectedLabel.value }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="clearRoom"
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="room"
                    v-model="roomPicker.query.value"
                    type="text"
                    placeholder="Search by Room code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="roomPicker.results.value.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="option in roomPicker.results.value"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="selectRoom(option)"
                    >
                        {{ option.code }} — {{ option.name }}
                    </li>
                </ul>
                <p v-if="form.errors.room_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.room_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="period">Period</label>
                <div
                    v-if="periodPicker.selectedLabel.value"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ periodPicker.selectedLabel.value }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="
                            form.period_id = '';
                            periodPicker.selectedLabel.value = '';
                        "
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="period"
                    v-model="periodPicker.query.value"
                    type="text"
                    placeholder="Search by Period code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="periodPicker.results.value.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="option in periodPicker.results.value"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="selectPeriod(option)"
                    >
                        {{ option.code }} ({{ option.startTime }}–{{ option.endTime }})
                    </li>
                </ul>
                <p v-if="form.errors.period_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.period_id }}
                </p>
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
