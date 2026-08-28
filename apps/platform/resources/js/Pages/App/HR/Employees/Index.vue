<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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

interface Props {
    employees: {
        data: EmployeeRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        search: string;
        include_archived: boolean;
    };
    canManage: boolean;
}

const props = defineProps<Props>();

const search = ref(props.filters.search);
const includeArchived = ref(props.filters.include_archived);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/hr/employees',
        {
            search: search.value || undefined,
            include_archived: includeArchived.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

const hasFilters = search.value !== '';
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
            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                <input v-model="includeArchived" type="checkbox" @change="applyFilters" />
                Include archived
            </label>
        </form>

        <EmptyState
            v-if="employees.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No employees match your search' : 'No employees yet'"
            :description="
                hasFilters
                    ? 'Try a different name or employee number.'
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

            <Pagination :links="employees.links" />
        </template>
    </main>
</template>
