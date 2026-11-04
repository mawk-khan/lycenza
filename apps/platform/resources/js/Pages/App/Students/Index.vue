<script setup lang="ts">
import ReportExportActions from '../../../Components/Reports/ReportExportActions.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface StudentRow {
    id: string;
    studentNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
    // Absent for a Campus-scoped reader (ORG.7F-1): their Directory holds
    // active Students only.
    status?: 'active' | 'inactive';
    // Present only when the viewer holds enrollments.view (V11.2); null when
    // the Student has no active placement in the School's active year.
    // `campus` is sent to a Campus-scoped reader only.
    currentPlacement?: {
        campus?: string | null;
        gradeLevel: string | null;
        section: string | null;
        rollNumber: string | null;
    } | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    students: {
        data: StudentRow[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        student_number: string;
        name: string;
        status: string;
        campus_id: string;
        grade_level_id: string;
        section_id: string;
        // SIS 11.1: the closed sort ('' = by name).
        sort: string;
    };
    // SIS.EXP-1: current-placement filter options, cut by the server to the
    // viewer's own reach (null without placement read or an active year).
    placementFilters: {
        campuses: { id: string; name: string }[];
        gradeLevels: { id: string; name: string }[];
        sections: { id: string; label: string; campusId: string; gradeLevelId: string }[];
    } | null;
    canManage: boolean;
    canViewEnrollments: boolean;
    // ORG.7F-1 (ADR 0076 §45): a Campus-scoped reader -- rows open the
    // Campus-safe overview, never the whole-School Profile.
    campusView: boolean;
    // ORG.7F-2: atomic Campus onboarding (students.manage AND enrollments.manage).
    canOnboard: boolean;
    canViewCommunities: boolean;
    // SIS 11.13: Students → Bulk operations (bulk Section change / Campus transfer).
    canBulkMove?: boolean;
    // SIS 11.4: governed export/print, when this viewer may.
    canExport?: boolean;
}

const props = defineProps<Props>();

const studentNumber = ref(props.filters.student_number);
const name = ref(props.filters.name);
const status = ref(props.filters.status);
const campusId = ref(props.filters.campus_id);
const gradeLevelId = ref(props.filters.grade_level_id);
const sectionId = ref(props.filters.section_id);
const sort = ref(props.filters.sort);

// SIS.EXP-1: only Sections that fit the chosen Campus and Grade are offered;
// choosing a Campus or Grade that excludes the chosen Section clears it (the
// server would match nothing for such a combination anyway).
const sectionOptions = computed(() =>
    (props.placementFilters?.sections ?? []).filter(
        (s) =>
            (campusId.value === '' || s.campusId === campusId.value) &&
            (gradeLevelId.value === '' || s.gradeLevelId === gradeLevelId.value),
    ),
);

function onPlacementChange(): void {
    if (sectionId.value !== '' && !sectionOptions.value.some((s) => s.id === sectionId.value)) {
        sectionId.value = '';
    }
    applyFilters();
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/students',
        {
            student_number: studentNumber.value || undefined,
            name: name.value || undefined,
            status: status.value || undefined,
            campus_id: campusId.value || undefined,
            grade_level_id: gradeLevelId.value || undefined,
            section_id: sectionId.value || undefined,
            sort: sort.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Modest debounce for the free-text filters -- a status <select>
// change applies immediately (no typing involved, no debounce needed).
watch([studentNumber, name], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

function fullName(student: StudentRow): string {
    return [student.firstName, student.middleName, student.lastName].filter(Boolean).join(' ');
}

// Reactive (it was computed once at setup and went stale after filtering).
const hasFilters = computed(
    () =>
        props.filters.student_number !== '' ||
        props.filters.name !== '' ||
        props.filters.status !== '' ||
        props.filters.campus_id !== '' ||
        props.filters.grade_level_id !== '' ||
        props.filters.section_id !== '',
);

function resetFilters(): void {
    studentNumber.value = '';
    name.value = '';
    status.value = '';
    campusId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    clearTimeout(debounceTimer);
    applyFilters();
}

function placementLabel(student: StudentRow): string {
    const p = student.currentPlacement;
    if (!p) {
        return 'No current class';
    }
    const cls = [p.gradeLevel, p.section, p.campus].filter(Boolean).join(' · ');
    return p.rollNumber ? `${cls} (roll ${p.rollNumber})` : cls;
}

function studentHref(student: StudentRow): string {
    return props.campusView
        ? `/app/students/${student.id}/overview`
        : `/app/students/${student.id}`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Home</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Students</h1>
                <p class="mt-1 text-sm text-ink-muted">Your School's student directory.</p>
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-2">
                <a
                    v-if="canViewCommunities"
                    href="/app/students/communities"
                    class="btn btn-secondary"
                >
                    Communities
                </a>
                <a
                    v-if="canBulkMove"
                    href="/app/students/bulk-operations"
                    class="btn btn-secondary"
                >
                    Bulk operations
                </a>
                <a v-if="canOnboard" href="/app/students/onboard" class="btn btn-secondary">
                    Onboard student
                </a>
                <a v-if="canManage" href="/app/students/create" class="btn btn-primary">
                    Add student
                </a>
            </div>
        </div>

        <ReportExportActions
            v-if="props.canExport"
            class="mt-4"
            report-key="students.directory"
            :query="props.filters"
        />

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-student-number"
                    >Student number</label
                >
                <input
                    id="filter-student-number"
                    v-model="studentNumber"
                    type="text"
                    class="input mt-1 w-40"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-name">Name</label>
                <input id="filter-name" v-model="name" type="text" class="input mt-1 w-48" />
            </div>
            <div v-if="!campusView">
                <label class="block text-sm text-ink-muted" for="filter-status">Status</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="input mt-1 w-36"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <template v-if="placementFilters">
                <div v-if="placementFilters.campuses.length > 1">
                    <label class="block text-sm text-ink-muted" for="filter-campus">Campus</label>
                    <select
                        id="filter-campus"
                        v-model="campusId"
                        class="input mt-1 w-44"
                        @change="onPlacementChange"
                    >
                        <option value="">All</option>
                        <option v-for="c in placementFilters.campuses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="filter-grade">Grade</label>
                    <select
                        id="filter-grade"
                        v-model="gradeLevelId"
                        class="input mt-1 w-36"
                        @change="onPlacementChange"
                    >
                        <option value="">All</option>
                        <option v-for="g in placementFilters.gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="filter-section">Section</label>
                    <select
                        id="filter-section"
                        v-model="sectionId"
                        class="input mt-1 w-56"
                        @change="applyFilters"
                    >
                        <option value="">All</option>
                        <option v-for="o in sectionOptions" :key="o.id" :value="o.id">
                            {{ o.label }}
                        </option>
                    </select>
                </div>
            </template>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-sort">Sort by</label>
                <select
                    id="filter-sort"
                    v-model="sort"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                >
                    <option value="">Name</option>
                    <option value="student_number">Student number</option>
                </select>
            </div>
            <button
                v-if="hasFilters"
                type="button"
                class="pb-2 text-sm underline"
                @click="resetFilters"
            >
                Clear filters
            </button>
        </form>
        <p v-if="placementFilters" class="mt-2 text-sm text-ink-muted">
            Campus, Grade and Section filter by the current class (this academic year). For other
            years or roll numbers, use the
            <a class="underline" href="/app/enrollments">Enrollments directory</a>.
        </p>

        <EmptyState
            v-if="students.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No students match your search' : 'No students yet'"
            :description="
                hasFilters
                    ? 'Try a different Student Number, name, status or class filter.'
                    : 'Add the first student to begin building your School\'s student directory.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a href="/app/students/create" class="btn btn-primary"> Add student </a>
            </template>
        </EmptyState>

        <template v-else>
            <!-- Desktop table -->
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Student number</th>
                        <th v-if="canViewEnrollments" scope="col" class="py-2 font-medium">
                            Current class
                        </th>
                        <th v-if="!campusView" scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="student in students.data" :key="student.id">
                        <td class="py-3">
                            <a class="font-medium underline" :href="studentHref(student)">{{
                                fullName(student)
                            }}</a>
                        </td>
                        <td class="py-3 text-ink-muted">{{ student.studentNumber }}</td>
                        <td v-if="canViewEnrollments" class="py-3 text-ink-muted">
                            {{ placementLabel(student) }}
                        </td>
                        <td v-if="student.status" class="py-3">
                            <StatusBadge :status="student.status" />
                        </td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="studentHref(student)">View</a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Mobile/tablet card list -->
            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="student in students.data"
                    :key="student.id"
                    class="rounded-xs border border-line p-4"
                >
                    <a class="font-medium underline" :href="studentHref(student)">{{
                        fullName(student)
                    }}</a>
                    <p class="mt-1 text-sm text-ink-muted">{{ student.studentNumber }}</p>
                    <p v-if="canViewEnrollments" class="mt-1 text-sm text-ink-muted">
                        {{ placementLabel(student) }}
                    </p>
                    <div v-if="student.status" class="mt-2">
                        <StatusBadge :status="student.status" />
                    </div>
                </li>
            </ul>

            <Pagination
                :links="students.links"
                :from="students.from"
                :to="students.to"
                :total="students.total"
            />
        </template>
    </main>
</template>
