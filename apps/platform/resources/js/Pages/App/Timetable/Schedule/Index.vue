<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface EntryRow {
    id: string;
    status: 'active' | 'inactive';
    dayOfWeek: number;
    subjectCode: string | null;
    subjectName: string | null;
    sectionCode: string | null;
    sectionName: string | null;
    classLabel: string | null;
    // Teacher: id + display name ONLY -- never work_email/work_phone or
    // any other HR field (Sensitive-tier data minimization).
    teacherId: string;
    teacherName: string | null;
    roomCode: string | null;
    roomName: string | null;
    periodCode: string | null;
    periodName: string | null;
    periodStartTime: string | null;
    periodEndTime: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    entries: {
        data: EntryRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        sectionId: string;
        teacherId: string;
    };
    // X-04: Sections and teachers on active timetable classes (no typed ids).
    filterOptions: {
        sections: { id: string; label: string }[];
        teachers: { id: string; label: string }[];
    };
    canManage: boolean;
}

const props = defineProps<Props>();

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

const sectionId = ref(props.filters.sectionId);
const teacherId = ref(props.filters.teacherId);

function applyFilters(resetTeacher = false): void {
    if (resetTeacher) {
        // Teacher choices narrow to the chosen Section.
        teacherId.value = '';
    }
    router.get(
        '/app/timetable-schedule',
        { section_id: sectionId.value || undefined, teacher_id: teacherId.value || undefined },
        { preserveScroll: true, replace: true },
    );
}

function clearFilters(): void {
    sectionId.value = '';
    teacherId.value = '';
    applyFilters();
}

const hasFilters = computed(() => props.filters.sectionId !== '' || props.filters.teacherId !== '');

function toggle(entry: EntryRow): void {
    const action = entry.status === 'active' ? 'deactivate' : 'activate';
    router.post(`/app/timetable-schedule/${entry.id}/${action}`);
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Timetable schedule</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Which Subject each Section is taught, by whom, when, and where.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/timetable-schedule/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Schedule a class
            </a>
        </div>

        <div class="mt-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm text-slate-600" for="filter-section">Section</label>
                <select
                    id="filter-section"
                    v-model="sectionId"
                    class="mt-1 w-64 max-w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters(true)"
                >
                    <option value="">All Sections</option>
                    <option v-for="s in filterOptions.sections" :key="s.id" :value="s.id">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-teacher">Teacher</label>
                <select
                    id="filter-teacher"
                    v-model="teacherId"
                    class="mt-1 w-64 max-w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters()"
                >
                    <option value="">All teachers</option>
                    <option v-for="t in filterOptions.teachers" :key="t.id" :value="t.id">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <button
                v-if="hasFilters"
                type="button"
                class="pb-2 text-sm underline"
                @click="clearFilters"
            >
                Clear filters
            </button>
        </div>

        <EmptyState
            v-if="entries.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No classes match this filter' : 'Nothing scheduled yet'"
            :description="
                hasFilters
                    ? 'Choose a different Section or teacher, or clear the filters.'
                    : 'Schedule the first class to begin building the weekly Timetable.'
            "
        >
            <template v-if="canManage && !sectionId && !teacherId" #action>
                <a
                    href="/app/timetable-schedule/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Schedule a class
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <!-- relative: keeps sr-only header labels inside the scroll box. -->
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Day</th>
                            <th scope="col" class="py-2 font-medium">Period</th>
                            <th scope="col" class="py-2 font-medium">Subject</th>
                            <th scope="col" class="py-2 font-medium">Section</th>
                            <th scope="col" class="py-2 font-medium">Teacher</th>
                            <th scope="col" class="py-2 font-medium">Room</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="entry in entries.data" :key="entry.id">
                            <td class="py-3">{{ DAY_NAMES[entry.dayOfWeek] }}</td>
                            <td class="py-3">
                                {{ entry.periodCode }}
                                <span v-if="entry.periodStartTime" class="text-xs text-slate-400">
                                    ({{ entry.periodStartTime }}–{{ entry.periodEndTime }})
                                </span>
                            </td>
                            <td class="py-3">{{ entry.subjectCode }} — {{ entry.subjectName }}</td>
                            <td class="py-3">{{ entry.classLabel ?? entry.sectionName }}</td>
                            <td class="py-3">{{ entry.teacherName }}</td>
                            <td class="py-3">{{ entry.roomCode ?? '—' }}</td>
                            <td class="py-3"><StatusBadge :status="entry.status" /></td>
                            <td class="py-3 text-right">
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="text-sm underline"
                                    @click="toggle(entry)"
                                >
                                    {{ entry.status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="entries.links" />
        </template>
    </main>
</template>
