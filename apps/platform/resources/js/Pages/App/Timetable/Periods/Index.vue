<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface PeriodRow {
    id: string;
    code: string;
    name: string;
    startTime: string;
    endTime: string;
    status: 'active' | 'inactive';
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    periods: {
        data: PeriodRow[];
        links: PageLink[];
        total: number;
    };
    // LC-2: active / inactive / all -- a deactivated Period stays findable.
    status: 'active' | 'inactive' | 'all';
    canManage: boolean;
}

const props = defineProps<Props>();
const statusFilter = ref(props.status);

function applyStatus(): void {
    router.get(
        '/app/timetable-periods',
        { status: statusFilter.value === 'active' ? undefined : statusFilter.value },
        { preserveScroll: true, replace: true },
    );
}

function toggleStatus(period: PeriodRow): void {
    router.patch(`/app/timetable-periods/${period.id}`, {
        status: period.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Timetable periods</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Named time slots ("Period 1", 09:00-09:45) the weekly schedule is built against.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/timetable-periods/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add Period
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-status">Status</label>
            <select
                id="filter-status"
                v-model="statusFilter"
                class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                @change="applyStatus"
            >
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="all">All</option>
            </select>
        </div>

        <EmptyState
            v-if="periods.data.length === 0"
            class="mt-6"
            :title="status === 'active' ? 'No Periods yet' : 'No Periods with this status'"
            :description="
                status === 'active'
                    ? 'Add the first Period to begin building the weekly Timetable.'
                    : 'Choose a different status.'
            "
        >
            <template v-if="canManage && status === 'active'" #action>
                <a
                    href="/app/timetable-periods/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add Period
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <!-- relative: keeps sr-only header labels inside the scroll box. -->
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Code</th>
                            <th scope="col" class="py-2 font-medium">Name</th>
                            <th scope="col" class="py-2 font-medium">Start</th>
                            <th scope="col" class="py-2 font-medium">End</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="period in periods.data" :key="period.id">
                            <td class="py-3 font-medium">{{ period.code }}</td>
                            <td class="py-3">{{ period.name }}</td>
                            <td class="py-3 font-mono">{{ period.startTime }}</td>
                            <td class="py-3 font-mono">{{ period.endTime }}</td>
                            <td class="py-3"><StatusBadge :status="period.status" /></td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <a
                                    v-if="canManage"
                                    class="mr-3 text-sm underline"
                                    :href="`/app/timetable-periods/${period.id}/edit`"
                                    >Edit<span class="sr-only"> {{ period.code }}</span></a
                                >
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="text-sm underline"
                                    @click="toggleStatus(period)"
                                >
                                    {{ period.status === 'active' ? 'Deactivate' : 'Activate'
                                    }}<span class="sr-only"> {{ period.code }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="periods.links" />
        </template>
    </main>
</template>
