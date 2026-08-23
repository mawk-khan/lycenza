<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface StudentRow {
    id: string;
    studentNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
    status: 'active' | 'inactive';
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
        total: number;
    };
    filters: {
        student_number: string;
        name: string;
        status: string;
    };
    canManage: boolean;
}

const props = defineProps<Props>();

const studentNumber = ref(props.filters.student_number);
const name = ref(props.filters.name);
const status = ref(props.filters.status);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/students',
        {
            student_number: studentNumber.value || undefined,
            name: name.value || undefined,
            status: status.value || undefined,
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

const hasFilters = studentNumber.value || name.value || status.value;
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Students</h1>
                <p class="mt-1 text-sm text-slate-500">Your School's student directory.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/students/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add student
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-student-number"
                    >Student number</label
                >
                <input
                    id="filter-student-number"
                    v-model="studentNumber"
                    type="text"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-name">Name</label>
                <input
                    id="filter-name"
                    v-model="name"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
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
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="students.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No students match your search' : 'No students yet'"
            :description="
                hasFilters
                    ? 'Try a different Student Number, name, or status.'
                    : 'Add the first student to begin building your School\'s student directory.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a
                    href="/app/students/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add student
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <!-- Desktop table -->
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Student number</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="student in students.data" :key="student.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/students/${student.id}`"
                                >{{ fullName(student) }}</a
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ student.studentNumber }}</td>
                        <td class="py-3"><StatusBadge :status="student.status" /></td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="`/app/students/${student.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Mobile/tablet card list -->
            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="student in students.data"
                    :key="student.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a class="font-medium underline" :href="`/app/students/${student.id}`">{{
                        fullName(student)
                    }}</a>
                    <p class="mt-1 text-sm text-slate-500">{{ student.studentNumber }}</p>
                    <div class="mt-2"><StatusBadge :status="student.status" /></div>
                </li>
            </ul>

            <Pagination :links="students.links" />
        </template>
    </main>
</template>
