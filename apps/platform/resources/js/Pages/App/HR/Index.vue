<script setup lang="ts">
interface Props {
    can: {
        viewEmployees: boolean;
        manageEmployees: boolean;
        viewDepartments: boolean;
        viewPositions: boolean;
        viewCategories: boolean;
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">HR</h1>
        <p class="mt-1 text-sm text-slate-500">
            Employee records, employment and reporting history, and organizational reference data.
        </p>

        <ul class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-if="can.viewEmployees" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/hr/employees">Employees</a>
                <p class="mt-1 text-sm text-slate-500">
                    The Employee directory{{
                        can.manageEmployees ? ', profiles, and lifecycle actions' : ''
                    }}.
                </p>
            </li>
            <li v-if="can.manageEmployees" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/hr/employees/import"
                    >Import employees</a
                >
                <p class="mt-1 text-sm text-slate-500">
                    Bulk-create Employees from a prepared row set.
                </p>
            </li>
            <li v-if="can.viewDepartments" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/hr/departments">Departments</a>
                <p class="mt-1 text-sm text-slate-500">Organizational Department reference data.</p>
            </li>
            <li v-if="can.viewPositions" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/hr/positions">Positions</a>
                <p class="mt-1 text-sm text-slate-500">Job title reference data.</p>
            </li>
            <li v-if="can.viewCategories" class="px-4 py-3">
                <a class="text-sm font-medium underline" href="/app/hr/categories"
                    >Employee categories</a
                >
                <p class="mt-1 text-sm text-slate-500">
                    Teaching / Non-Teaching / Contract / Visiting classification.
                </p>
            </li>
        </ul>

        <p
            v-if="
                !can.viewEmployees &&
                !can.viewDepartments &&
                !can.viewPositions &&
                !can.viewCategories
            "
            class="mt-6 text-sm text-slate-500"
        >
            You don't have access to any HR area yet.
        </p>
    </main>
</template>
