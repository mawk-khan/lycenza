<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { ENROLLMENT_STATUSES } from '../../../enrollmentStatuses';

interface Ref {
    id: string;
    name: string;
}

interface EnrollmentRow {
    id: string;
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    academicYear: Ref;
    campus: Ref;
    gradeLevel: Ref;
    section: Ref;
    rollNumber: string;
    status: 'active' | 'completed' | 'withdrawn' | 'transferred' | 'cancelled';
    startsOn: string;
    endsOn: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    enrollments: {
        data: EnrollmentRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        academic_year_id: string;
        campus_id: string;
        grade_level_id: string;
        section_id: string;
        status: string;
        student_number: string;
        student_name: string;
        roll_number: string;
    };
    academicYears: Ref[];
    campuses: Ref[];
    gradeLevels: Ref[];
    sections: Array<Ref & { label: string }>;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academic_year_id);
const campusId = ref(props.filters.campus_id);
const gradeLevelId = ref(props.filters.grade_level_id);
const sectionId = ref(props.filters.section_id);
const status = ref(props.filters.status);
const studentNumber = ref(props.filters.student_number);
const studentName = ref(props.filters.student_name);
const rollNumber = ref(props.filters.roll_number);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/enrollments',
        {
            academic_year_id: academicYearId.value || undefined,
            campus_id: campusId.value || undefined,
            grade_level_id: gradeLevelId.value || undefined,
            section_id: sectionId.value || undefined,
            status: status.value || undefined,
            student_number: studentNumber.value || undefined,
            student_name: studentName.value || undefined,
            roll_number: rollNumber.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Modest debounce for the free-text filters only -- select changes
// apply immediately (no typing involved), matching Students/Index.vue.
watch([studentNumber, studentName, rollNumber], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

function enrollmentStudentName(student: EnrollmentRow['student']): string {
    return [student.firstName, student.middleName, student.lastName].filter(Boolean).join(' ');
}

const hasFilters = Object.values(props.filters).some((v) => v !== '');
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2">
            <h1 class="text-xl font-semibold">Enrollments</h1>
            <p class="mt-1 text-sm text-slate-500">Academic placement across your School.</p>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-academic-year"
                    >Academic Year</label
                >
                <select
                    id="filter-academic-year"
                    v-model="academicYearId"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-campus">Campus</label>
                <select
                    id="filter-campus"
                    v-model="campusId"
                    class="mt-1 w-36 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-grade">Grade</label>
                <select
                    id="filter-grade"
                    v-model="gradeLevelId"
                    class="mt-1 w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-section">Section</label>
                <select
                    id="filter-section"
                    v-model="sectionId"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-status">Status</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="mt-1 w-36 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="s in ENROLLMENT_STATUSES" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-student-number"
                    >Student number</label
                >
                <input
                    id="filter-student-number"
                    v-model="studentNumber"
                    type="text"
                    class="mt-1 w-36 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-student-name"
                    >Student name</label
                >
                <input
                    id="filter-student-name"
                    v-model="studentName"
                    type="text"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-roll-number"
                    >Roll number</label
                >
                <input
                    id="filter-roll-number"
                    v-model="rollNumber"
                    type="text"
                    class="mt-1 w-28 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
        </form>

        <EmptyState
            v-if="enrollments.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No enrollments match your search' : 'No enrollments yet'"
            :description="
                hasFilters
                    ? 'Try different filters.'
                    : 'Enroll a student from their profile page to see them here.'
            "
        />

        <template v-else>
            <!-- Desktop table -->
            <div class="mt-6 hidden overflow-x-auto md:block">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th scope="col" class="py-2 font-medium">Student number</th>
                            <th scope="col" class="py-2 font-medium">Academic Year</th>
                            <th scope="col" class="py-2 font-medium">Campus</th>
                            <th scope="col" class="py-2 font-medium">Grade</th>
                            <th scope="col" class="py-2 font-medium">Section</th>
                            <th scope="col" class="py-2 font-medium">Roll number</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="e in enrollments.data" :key="e.id">
                            <td class="py-3">
                                <a
                                    class="font-medium underline"
                                    :href="`/app/students/${e.student.id}`"
                                    >{{ enrollmentStudentName(e.student) }}</a
                                >
                            </td>
                            <td class="py-3 text-slate-600">{{ e.student.studentNumber }}</td>
                            <td class="py-3 text-slate-600">{{ e.academicYear.name }}</td>
                            <td class="py-3 text-slate-600">{{ e.campus.name }}</td>
                            <td class="py-3 text-slate-600">{{ e.gradeLevel.name }}</td>
                            <td class="py-3 text-slate-600">{{ e.section.name }}</td>
                            <td class="py-3 text-slate-600">{{ e.rollNumber }}</td>
                            <td class="py-3"><StatusBadge :status="e.status" /></td>
                            <td class="py-3 text-right">
                                <a class="text-sm underline" :href="`/app/students/${e.student.id}`"
                                    >View student</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile/tablet card list -->
            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="e in enrollments.data"
                    :key="e.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a class="font-medium underline" :href="`/app/students/${e.student.id}`">{{
                        enrollmentStudentName(e.student)
                    }}</a>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ e.gradeLevel.name }} · Section {{ e.section.name }} · Roll
                        {{ e.rollNumber }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ e.academicYear.name }} · {{ e.campus.name }}
                    </p>
                    <div class="mt-2"><StatusBadge :status="e.status" /></div>
                </li>
            </ul>

            <Pagination :links="enrollments.links" />
        </template>
    </main>
</template>
