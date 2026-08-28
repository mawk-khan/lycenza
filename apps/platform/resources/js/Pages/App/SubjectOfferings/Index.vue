<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface Ref {
    id: string;
    name: string;
}

interface OfferingRow {
    id: string;
    subject: (Ref & { code: string }) | null;
    academicYear: (Ref & { code: string }) | null;
    campus: Ref | null;
    gradeLevel: Ref | null;
    status: 'active' | 'inactive';
    isRequired: boolean;
    electiveGroup: Ref | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    offerings: {
        data: OfferingRow[];
        links: PageLink[];
        total: number;
    };
    filters: { academic_year_id: string };
    academicYears: Array<Ref & { code: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academic_year_id);

function applyFilters(): void {
    router.get(
        '/app/subject-offerings',
        { academic_year_id: academicYearId.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2">
            <h1 class="text-xl font-semibold">Subject Offerings</h1>
            <p class="mt-1 text-sm text-slate-500">
                View rosters and manage elective participation for a SubjectOffering.
            </p>
        </div>

        <form class="mt-6 flex items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-academic-year"
                    >Academic Year</label
                >
                <select
                    id="filter-academic-year"
                    v-model="academicYearId"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }} ({{ y.code }})
                    </option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="offerings.data.length === 0"
            class="mt-6"
            title="No Subject Offerings"
            description="No Subject Offerings exist for this Academic Year yet -- configure them from Academic Structure setup."
        />

        <template v-else>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Subject</th>
                            <th scope="col" class="py-2 font-medium">Grade</th>
                            <th scope="col" class="py-2 font-medium">Campus</th>
                            <th scope="col" class="py-2 font-medium">Type</th>
                            <th scope="col" class="py-2 font-medium">Elective Group</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="o in offerings.data" :key="o.id">
                            <td class="py-3">
                                <a
                                    class="font-medium underline"
                                    :href="`/app/subject-offerings/${o.id}`"
                                    >{{ o.subject?.name ?? 'Unknown Subject' }}</a
                                >
                            </td>
                            <td class="py-3 text-slate-600">{{ o.gradeLevel?.name }}</td>
                            <td class="py-3 text-slate-600">{{ o.campus?.name }}</td>
                            <td class="py-3 text-slate-600">
                                {{ o.isRequired ? 'Required' : 'Elective' }}
                            </td>
                            <td class="py-3 text-slate-600">
                                {{ o.electiveGroup?.name ?? 'Ungrouped' }}
                            </td>
                            <td class="py-3"><StatusBadge :status="o.status" /></td>
                            <td class="py-3 text-right">
                                <a
                                    class="text-sm underline"
                                    :href="`/app/subject-offerings/${o.id}`"
                                    >View roster</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="offerings.links" />
        </template>
    </main>
</template>
