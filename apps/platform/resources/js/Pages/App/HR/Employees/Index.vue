<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface EmployeeRow {
    employee_id: string;
    employee_number: string;
    display_name: string;
    position_name: string | null;
    department_name: string | null;
    campus_name: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Option {
    id: string;
    name: string;
}

interface Props {
    employees: {
        data: EmployeeRow[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        campus_id: string;
        department_id: string;
        position_id: string;
        sort: 'full_name' | 'employee_number';
        direction: 'asc' | 'desc';
        include_archived: boolean;
    };
    campusOptions: Option[];
    // null when the viewer does not hold hr.departments.view / hr.positions.view.
    departmentOptions: Option[] | null;
    positionOptions: Option[] | null;
    canManage: boolean;
}

const props = defineProps<Props>();

const search = ref(props.filters.search);
const campusId = ref(props.filters.campus_id);
const departmentId = ref(props.filters.department_id);
const positionId = ref(props.filters.position_id);
const sortKey = ref(`${props.filters.sort}:${props.filters.direction}`);
const includeArchived = ref(props.filters.include_archived);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    const [sort, direction] = sortKey.value.split(':');
    router.get(
        '/app/hr/employees',
        {
            search: search.value || undefined,
            campus_id: campusId.value || undefined,
            department_id: departmentId.value || undefined,
            position_id: positionId.value || undefined,
            sort: sortKey.value === 'full_name:asc' ? undefined : sort,
            direction: sortKey.value === 'full_name:asc' ? undefined : direction,
            include_archived: includeArchived.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

// Reactive (it was computed once at setup, so the empty state could
// describe the previous filter state).
const hasFilters = computed(
    () =>
        props.filters.search !== '' ||
        props.filters.campus_id !== '' ||
        props.filters.department_id !== '' ||
        props.filters.position_id !== '',
);

function resetFilters(): void {
    search.value = '';
    campusId.value = '';
    departmentId.value = '';
    positionId.value = '';
    sortKey.value = 'full_name:asc';
    includeArchived.value = false;
    clearTimeout(debounceTimer);
    applyFilters();
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Employees</h1>
                <p class="mt-1 text-sm text-slate-500">Your School's Employee directory.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/hr/employees/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add employee
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-search">Search</label>
                <input
                    id="filter-search"
                    v-model="search"
                    type="text"
                    placeholder="Name or employee number…"
                    class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-campus">Campus</label>
                <select
                    id="filter-campus"
                    v-model="campusId"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All campuses</option>
                    <option v-for="campus in campusOptions" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
            </div>
            <div v-if="departmentOptions !== null">
                <label class="block text-sm text-slate-600" for="filter-department"
                    >Department</label
                >
                <select
                    id="filter-department"
                    v-model="departmentId"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All departments</option>
                    <option
                        v-for="department in departmentOptions"
                        :key="department.id"
                        :value="department.id"
                    >
                        {{ department.name }}
                    </option>
                </select>
            </div>
            <div v-if="positionOptions !== null">
                <label class="block text-sm text-slate-600" for="filter-position">Position</label>
                <select
                    id="filter-position"
                    v-model="positionId"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All positions</option>
                    <option
                        v-for="position in positionOptions"
                        :key="position.id"
                        :value="position.id"
                    >
                        {{ position.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-sort">Sort by</label>
                <select
                    id="filter-sort"
                    v-model="sortKey"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="full_name:asc">Name (A–Z)</option>
                    <option value="full_name:desc">Name (Z–A)</option>
                    <option value="employee_number:asc">Employee number (ascending)</option>
                    <option value="employee_number:desc">Employee number (descending)</option>
                </select>
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                <input v-model="includeArchived" type="checkbox" @change="applyFilters" />
                Include archived
            </label>
            <button
                v-if="hasFilters || includeArchived || sortKey !== 'full_name:asc'"
                type="button"
                class="pb-2 text-sm underline"
                @click="resetFilters"
            >
                Clear filters
            </button>
        </form>

        <EmptyState
            v-if="employees.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No employees match your search' : 'No employees yet'"
            :description="
                hasFilters
                    ? 'Try a different name or employee number, or clear the filters.'
                    : 'Add the first employee to begin building your School\'s HR records.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a
                    href="/app/hr/employees/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add employee
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th scope="col" class="py-2 font-medium">Employee number</th>
                        <th scope="col" class="py-2 font-medium">Position</th>
                        <th scope="col" class="py-2 font-medium">Department</th>
                        <th scope="col" class="py-2 font-medium">Campus</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="employee in employees.data" :key="employee.employee_id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/hr/employees/${employee.employee_id}`"
                                >{{ employee.display_name }}</a
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ employee.employee_number }}</td>
                        <td class="py-3 text-slate-600">{{ employee.position_name ?? '—' }}</td>
                        <td class="py-3 text-slate-600">{{ employee.department_name ?? '—' }}</td>
                        <td class="py-3 text-slate-600">{{ employee.campus_name ?? '—' }}</td>
                        <td class="py-3 text-right">
                            <a
                                class="text-sm underline"
                                :href="`/app/hr/employees/${employee.employee_id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="employee in employees.data"
                    :key="employee.employee_id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a
                        class="font-medium underline"
                        :href="`/app/hr/employees/${employee.employee_id}`"
                        >{{ employee.display_name }}</a
                    >
                    <p class="mt-1 text-sm text-slate-500">{{ employee.employee_number }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ employee.position_name ?? '—' }} · {{ employee.department_name ?? '—' }}
                    </p>
                </li>
            </ul>

            <Pagination
                :links="employees.links"
                :from="employees.from"
                :to="employees.to"
                :total="employees.total"
            />
        </template>
    </main>
</template>
