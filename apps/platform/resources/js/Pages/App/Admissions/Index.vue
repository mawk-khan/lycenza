<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface Ref {
    id: string;
    name: string;
}

interface ApplicantRef {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface ApplicationRow {
    id: string;
    status: 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'converted';
    applicant: ApplicantRef;
    academicYear: Ref;
    campus: Ref;
    gradeLevel: Ref;
    createdAt: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    applications: {
        data: ApplicationRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        status: string;
        academic_year_id: string;
        campus_id: string;
        grade_level_id: string;
        applicant_name: string;
    };
    academicYears: Ref[];
    campuses: Ref[];
    gradeLevels: Ref[];
    canManage: boolean;
}

const props = defineProps<Props>();

const status = ref(props.filters.status);
const academicYearId = ref(props.filters.academic_year_id);
const campusId = ref(props.filters.campus_id);
const gradeLevelId = ref(props.filters.grade_level_id);
const applicantName = ref(props.filters.applicant_name);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/admissions',
        {
            status: status.value || undefined,
            academic_year_id: academicYearId.value || undefined,
            campus_id: campusId.value || undefined,
            grade_level_id: gradeLevelId.value || undefined,
            applicant_name: applicantName.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(applicantName, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

function applicantName_(applicant: ApplicantRef): string {
    return [applicant.firstName, applicant.middleName, applicant.lastName]
        .filter(Boolean)
        .join(' ');
}

function applicationContext(a: ApplicationRow): string {
    return `${a.gradeLevel.name} · ${a.campus.name} · ${a.academicYear.name}`;
}

const hasFilters =
    status.value ||
    academicYearId.value ||
    campusId.value ||
    gradeLevelId.value ||
    applicantName.value;
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Admissions</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Admission Applications across your School.
                </p>
            </div>
            <a href="/app/admissions/applicants" class="text-sm font-medium underline"
                >Applicants</a
            >
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-applicant-name"
                    >Applicant</label
                >
                <input
                    id="filter-applicant-name"
                    v-model="applicantName"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-status">Status</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                    <option value="withdrawn">Withdrawn</option>
                    <option value="converted">Converted</option>
                </select>
            </div>
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
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
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
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="applications.data.length === 0"
            class="mt-6"
            :title="
                hasFilters ? 'No applications match your filters' : 'No Admission Applications yet'
            "
            description="Applications are created from an Applicant's own page."
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Applicant</th>
                        <th scope="col" class="py-2 font-medium">Applying for</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="a in applications.data" :key="a.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/admissions/applicants/${a.applicant.id}`"
                                >{{ applicantName_(a.applicant) }}</a
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ applicationContext(a) }}</td>
                        <td class="py-3"><StatusBadge :status="a.status" /></td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="`/app/admissions/${a.id}`">View</a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="a in applications.data"
                    :key="a.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a class="font-medium underline" :href="`/app/admissions/${a.id}`">{{
                        applicantName_(a.applicant)
                    }}</a>
                    <p class="mt-1 text-sm text-slate-500">{{ applicationContext(a) }}</p>
                    <div class="mt-2"><StatusBadge :status="a.status" /></div>
                </li>
            </ul>

            <Pagination :links="applications.links" />
        </template>
    </main>
</template>
