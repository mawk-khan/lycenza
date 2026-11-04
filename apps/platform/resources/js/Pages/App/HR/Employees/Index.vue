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
    manager_employee_id: string | null;
    manager_display_name: string | null;
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
        // ORG.9C-1 (ADR 0076 §69): current employment state, several Campuses,
        // joined in a period, direct reports of a manager -- narrowing only.
        employment: '' | 'current' | 'notice_period' | 'pre_joining' | 'ended';
        several_campuses: boolean;
        joined_from: string;
        joined_to: string;
        manager_id: string;
    };
    campusOptions: Option[];
    // null when the viewer does not hold hr.departments.view / hr.positions.view.
    departmentOptions: Option[] | null;
    positionOptions: Option[] | null;
    canManage: boolean;
    // ORG.7E-1: onboarding (Campus-aware), the whole-School Profile, the HR hub.
    canOnboard: boolean;
    canViewProfile: boolean;
    canOpenHub: boolean;
}

const props = defineProps<Props>();

// A Profile reader opens the Profile; any other Directory reader the
// operational overview (ORG.7E-1).
function employeeHref(id: string): string {
    return props.canViewProfile ? `/app/hr/employees/${id}` : `/app/hr/employees/${id}/overview`;
}

const search = ref(props.filters.search);
const campusId = ref(props.filters.campus_id);
const departmentId = ref(props.filters.department_id);
const positionId = ref(props.filters.position_id);
const sortKey = ref(`${props.filters.sort}:${props.filters.direction}`);
const includeArchived = ref(props.filters.include_archived);
const employment = ref(props.filters.employment);
const severalCampuses = ref(props.filters.several_campuses);
const joinedFrom = ref(props.filters.joined_from);
const joinedTo = ref(props.filters.joined_to);
const managerId = ref(props.filters.manager_id);

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
            employment: employment.value || undefined,
            several_campuses: severalCampuses.value || undefined,
            joined_from: joinedFrom.value || undefined,
            joined_to: joinedTo.value || undefined,
            manager_id: managerId.value || undefined,
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
        props.filters.position_id !== '' ||
        props.filters.employment !== '' ||
        props.filters.several_campuses ||
        props.filters.joined_from !== '' ||
        props.filters.joined_to !== '' ||
        props.filters.manager_id !== '',
);

function reportsOf(id: string): void {
    managerId.value = id;
    applyFilters();
}

function resetFilters(): void {
    search.value = '';
    campusId.value = '';
    departmentId.value = '';
    positionId.value = '';
    sortKey.value = 'full_name:asc';
    includeArchived.value = false;
    employment.value = '';
    severalCampuses.value = false;
    joinedFrom.value = '';
    joinedTo.value = '';
    managerId.value = '';
    clearTimeout(debounceTimer);
    applyFilters();
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a v-if="canOpenHub" class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Employees</h1>
                <p class="mt-1 text-sm text-ink-muted">Your School's Employee directory.</p>
            </div>
            <div class="flex shrink-0 gap-2">
                <a v-if="canOnboard" href="/app/hr/employees/onboard" class="btn btn-secondary">
                    Onboard employee
                </a>
                <a v-if="canManage" href="/app/hr/employees/create" class="btn btn-primary">
                    Add employee
                </a>
            </div>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-search">Search</label>
                <input
                    id="filter-search"
                    v-model="search"
                    type="text"
                    placeholder="Name or employee number…"
                    class="input mt-1 w-64"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-campus">Campus</label>
                <select
                    id="filter-campus"
                    v-model="campusId"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                >
                    <option value="">All campuses</option>
                    <option v-for="campus in campusOptions" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
            </div>
            <div v-if="departmentOptions !== null">
                <label class="block text-sm text-ink-muted" for="filter-department"
                    >Department</label
                >
                <select
                    id="filter-department"
                    v-model="departmentId"
                    class="input mt-1 w-auto"
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
                <label class="block text-sm text-ink-muted" for="filter-position">Position</label>
                <select
                    id="filter-position"
                    v-model="positionId"
                    class="input mt-1 w-auto"
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
                <label class="block text-sm text-ink-muted" for="filter-sort">Sort by</label>
                <select
                    id="filter-sort"
                    v-model="sortKey"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                >
                    <option value="full_name:asc">Name (A–Z)</option>
                    <option value="full_name:desc">Name (Z–A)</option>
                    <option value="employee_number:asc">Employee number (ascending)</option>
                    <option value="employee_number:desc">Employee number (descending)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="employee-employment"
                    >Employment</label
                >
                <select
                    id="employee-employment"
                    v-model="employment"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                >
                    <option value="">Any</option>
                    <option value="current">Currently employed</option>
                    <option value="notice_period">Serving notice</option>
                    <option value="pre_joining">Not yet joined</option>
                    <option value="ended">Employment ended</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="employee-joined-from"
                    >Joined from</label
                >
                <input
                    id="employee-joined-from"
                    v-model="joinedFrom"
                    type="date"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="employee-joined-to"
                    >Joined to</label
                >
                <input
                    id="employee-joined-to"
                    v-model="joinedTo"
                    type="date"
                    class="input mt-1 w-auto"
                    @change="applyFilters"
                />
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm text-ink-muted">
                <input v-model="severalCampuses" type="checkbox" @change="applyFilters" />
                On more than one campus
            </label>
            <label class="flex items-center gap-2 pb-2 text-sm text-ink-muted">
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

        <p v-if="filters.manager_id" class="mt-4 text-sm text-ink-muted">
            Showing the people who currently report to the selected manager.
            <button
                type="button"
                class="underline"
                @click="
                    managerId = '';
                    applyFilters();
                "
            >
                Show everyone
            </button>
        </p>

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
                <a href="/app/hr/employees/create" class="btn btn-primary"> Add employee </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th scope="col" class="py-2 font-medium">Employee number</th>
                        <th scope="col" class="py-2 font-medium">Position</th>
                        <th scope="col" class="py-2 font-medium">Department</th>
                        <th scope="col" class="py-2 font-medium">Campus</th>
                        <th scope="col" class="py-2 font-medium">Reports to</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="employee in employees.data" :key="employee.employee_id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="employeeHref(employee.employee_id)"
                                >{{ employee.display_name }}</a
                            >
                        </td>
                        <td class="py-3 text-ink-muted">{{ employee.employee_number }}</td>
                        <td class="py-3 text-ink-muted">{{ employee.position_name ?? '—' }}</td>
                        <td class="py-3 text-ink-muted">{{ employee.department_name ?? '—' }}</td>
                        <td class="py-3 text-ink-muted">{{ employee.campus_name ?? '—' }}</td>
                        <td class="py-3 text-ink-muted">
                            <button
                                v-if="employee.manager_employee_id"
                                type="button"
                                class="underline"
                                :title="`Show everyone reporting to ${employee.manager_display_name}`"
                                @click="reportsOf(employee.manager_employee_id)"
                            >
                                {{ employee.manager_display_name }}
                            </button>
                            <span v-else>—</span>
                        </td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="employeeHref(employee.employee_id)"
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
                    class="rounded-xs border border-line p-4"
                >
                    <a class="font-medium underline" :href="employeeHref(employee.employee_id)">{{
                        employee.display_name
                    }}</a>
                    <p class="mt-1 text-sm text-ink-muted">{{ employee.employee_number }}</p>
                    <p class="mt-1 text-sm text-ink-muted">
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
