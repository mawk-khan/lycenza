<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { ROLLOVER_PLAN_STATUSES } from '../../../rolloverStatuses';
import type { RolloverPlanStatus } from '../../../rolloverTypes';

interface YearRef {
    id: string;
    name: string;
    code: string;
}

interface PlanRow {
    id: string;
    sourceAcademicYear: YearRef;
    targetAcademicYear: YearRef;
    status: RolloverPlanStatus;
    configurationVersion: number;
    validatedConfigurationVersion: number | null;
    createdAt: string;
    validatedAt: string | null;
    executionStartedAt: string | null;
    completedAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    plans: {
        data: PlanRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        source_academic_year_id: string;
        target_academic_year_id: string;
        status: string;
    };
    academicYears: YearRef[];
    canManage: boolean;
}

const props = defineProps<Props>();

const sourceAcademicYearId = ref(props.filters.source_academic_year_id);
const targetAcademicYearId = ref(props.filters.target_academic_year_id);
const status = ref(props.filters.status);

function applyFilters(): void {
    router.get(
        '/app/enrollment-rollovers',
        {
            source_academic_year_id: sourceAcademicYearId.value || undefined,
            target_academic_year_id: targetAcademicYearId.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([sourceAcademicYearId, targetAcademicYearId, status], applyFilters);

const hasFilters = Object.values(props.filters).some((v) => v !== '');

function isReadyForConfigurationVersion(plan: PlanRow): boolean {
    return plan.validatedConfigurationVersion === plan.configurationVersion;
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Enrollment Rollovers</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Move Students from one Academic Year into the next.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/enrollment-rollovers/create"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Create Plan
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-source-year"
                    >Source Academic Year</label
                >
                <select
                    id="filter-source-year"
                    v-model="sourceAcademicYearId"
                    class="mt-1 w-44 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">All</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-target-year"
                    >Target Academic Year</label
                >
                <select
                    id="filter-target-year"
                    v-model="targetAcademicYearId"
                    class="mt-1 w-44 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">All</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-status">Status</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="mt-1 w-44 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">All</option>
                    <option v-for="s in ROLLOVER_PLAN_STATUSES" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="plans.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No rollover plans match your search' : 'No rollover plans yet'"
            :description="
                hasFilters
                    ? 'Try different filters.'
                    : canManage
                      ? 'Create a plan to move Students into the next Academic Year.'
                      : 'No rollover plans have been created yet.'
            "
        >
            <template v-if="!hasFilters && canManage" #action>
                <a href="/app/enrollment-rollovers/create" class="text-sm underline">Create Plan</a>
            </template>
        </EmptyState>

        <template v-else>
            <!-- Desktop table -->
            <div class="mt-6 hidden overflow-x-auto md:block">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Source Year</th>
                            <th scope="col" class="py-2 font-medium">Target Year</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">Validated</th>
                            <th scope="col" class="py-2 font-medium">Created</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="p in plans.data" :key="p.id">
                            <td class="py-3">{{ p.sourceAcademicYear.name }}</td>
                            <td class="py-3">{{ p.targetAcademicYear.name }}</td>
                            <td class="py-3"><StatusBadge :status="p.status" /></td>
                            <td class="py-3 text-slate-600">
                                {{ isReadyForConfigurationVersion(p) ? 'Yes' : 'No' }}
                            </td>
                            <td class="py-3 text-slate-600">
                                {{ new Date(p.createdAt).toLocaleDateString() }}
                            </td>
                            <td class="py-3 text-right">
                                <a
                                    class="text-sm underline"
                                    :href="`/app/enrollment-rollovers/${p.id}`"
                                    >Open</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile/tablet card list -->
            <ul class="mt-6 space-y-3 md:hidden">
                <li v-for="p in plans.data" :key="p.id" class="rounded border border-slate-200 p-4">
                    <a class="font-medium underline" :href="`/app/enrollment-rollovers/${p.id}`">
                        {{ p.sourceAcademicYear.name }} → {{ p.targetAcademicYear.name }}
                    </a>
                    <p class="mt-1 text-sm text-slate-500">
                        Created {{ new Date(p.createdAt).toLocaleDateString() }}
                    </p>
                    <div class="mt-2"><StatusBadge :status="p.status" /></div>
                </li>
            </ul>

            <Pagination :links="plans.links" />
        </template>
    </main>
</template>
