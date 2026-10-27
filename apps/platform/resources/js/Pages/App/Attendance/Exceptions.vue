<script setup lang="ts">
import { formatTimeRange } from '../../../datetime';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

/**
 * V11.2 (ADR 0075): one School day's absent, late and excused records
 * across every submitted register. An operational list of individual
 * records (the same ones each register page shows), never a rate or a
 * tally. Period times are the register's own immutable snapshot.
 */
interface ExceptionRow {
    id: string;
    status: 'absent' | 'late' | 'excused';
    correctedAt: string | null;
    studentId: string | null;
    fullName: string | null;
    rollNumber: string | null;
    sessionId: string | null;
    sectionName: string | null;
    classLabel: string | null;
    sectionCode: string | null;
    subjectName: string | null;
    periodName: string | null;
    periodStartTime: string | null;
    periodEndTime: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    records: {
        data: ExceptionRow[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { date: string; sectionId: string; status: string };
    sectionOptions: {
        id: string;
        name: string | null;
        code: string | null;
        label: string | null;
    }[];
    statuses: ExceptionRow['status'][];
    canViewStudents: boolean;
}

const props = defineProps<Props>();

const date = ref(props.filters.date);
const sectionId = ref(props.filters.sectionId);
const status = ref(props.filters.status);

const statusLabels: Record<ExceptionRow['status'], string> = {
    absent: 'Absent',
    late: 'Late',
    excused: 'Excused',
};

const statusClasses: Record<ExceptionRow['status'], string> = {
    absent: 'bg-red-50 text-red-800 ring-red-200',
    late: 'bg-amber-50 text-amber-800 ring-amber-200',
    excused: 'bg-slate-100 text-slate-700 ring-slate-200',
};

function apply(resetSection = false): void {
    if (resetSection) {
        // Section choices are per day: a Section from another day may have no register.
        sectionId.value = '';
    }
    router.get(
        '/app/attendance/exceptions',
        {
            date: date.value || undefined,
            section_id: sectionId.value || undefined,
            status: status.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
}

function clearFilters(): void {
    sectionId.value = '';
    status.value = '';
    apply();
}

const hasNarrowing = computed(() => props.filters.sectionId !== '' || props.filters.status !== '');

function periodLabel(row: ExceptionRow): string {
    const time = row.periodStartTime ? formatTimeRange(row.periodStartTime, row.periodEndTime) : '';
    return [row.periodName, time].filter(Boolean).join(' · ') || '—';
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/attendance">← Attendance registers</a>

        <h1 class="mt-2 text-xl font-semibold">Absent, late and excused</h1>
        <p class="mt-1 text-sm text-slate-500">
            Every Student marked absent, late or excused on one day, across all submitted registers.
            Open a register to correct a status.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply()">
            <div>
                <label class="block text-sm text-slate-600" for="exceptions-date">Date</label>
                <input
                    id="exceptions-date"
                    v-model="date"
                    type="date"
                    required
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply(true)"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="exceptions-section">Section</label>
                <select
                    id="exceptions-section"
                    v-model="sectionId"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply()"
                >
                    <option value="">All Sections with a register</option>
                    <option v-for="section in sectionOptions" :key="section.id" :value="section.id">
                        {{ section.label ?? section.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="exceptions-status">Status</label>
                <select
                    id="exceptions-status"
                    v-model="status"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply()"
                >
                    <option value="">Absent, late or excused</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabels[s] }}</option>
                </select>
            </div>
            <button
                v-if="hasNarrowing"
                type="button"
                class="pb-2 text-sm underline"
                @click="clearFilters"
            >
                Clear filters
            </button>
        </form>

        <EmptyState
            v-if="records.data.length === 0"
            class="mt-6"
            :title="
                sectionOptions.length === 0
                    ? 'No registers on this day'
                    : 'Nobody was absent, late or excused'
            "
            :description="
                sectionOptions.length === 0
                    ? 'No register has been submitted for this date yet. Choose another day.'
                    : hasNarrowing
                      ? 'No record matches these filters on this day. Clear the filters to see every Section.'
                      : 'Every Student on this day\'s submitted registers was marked present.'
            "
        />

        <template v-else>
            <!-- relative: keeps the sr-only header labels inside the scroll box. -->
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <caption class="sr-only">
                        Absent, late and excused Students on
                        {{
                            filters.date
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th scope="col" class="py-2 font-medium">Roll</th>
                            <th scope="col" class="py-2 font-medium">Section</th>
                            <th scope="col" class="py-2 font-medium">Period</th>
                            <th scope="col" class="py-2 font-medium">Subject</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Register</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in records.data" :key="row.id">
                            <td class="py-3">
                                <a
                                    v-if="canViewStudents && row.studentId"
                                    class="font-medium underline"
                                    :href="`/app/students/${row.studentId}`"
                                    >{{ row.fullName ?? '—' }}</a
                                >
                                <span v-else class="font-medium">{{ row.fullName ?? '—' }}</span>
                            </td>
                            <td class="py-3 text-slate-600">{{ row.rollNumber ?? '—' }}</td>
                            <td class="py-3 text-slate-600">
                                {{ row.classLabel ?? row.sectionName ?? row.sectionCode ?? '—' }}
                            </td>
                            <td class="py-3 text-slate-600">{{ periodLabel(row) }}</td>
                            <td class="py-3 text-slate-600">{{ row.subjectName ?? '—' }}</td>
                            <td class="py-3">
                                <span
                                    class="inline-flex rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusClasses[row.status]"
                                    >{{ statusLabels[row.status] }}</span
                                >
                                <span v-if="row.correctedAt" class="ml-1 text-xs text-slate-500"
                                    >(corrected)</span
                                >
                            </td>
                            <td class="py-3 text-right">
                                <a
                                    v-if="row.sessionId"
                                    class="text-sm underline"
                                    :href="`/app/attendance/${row.sessionId}`"
                                    >Register</a
                                >
                                <a
                                    v-if="row.studentId"
                                    class="ml-3 text-sm underline"
                                    :href="`/app/attendance/students/${row.studentId}`"
                                    >History</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination
                :links="records.links"
                :from="records.from"
                :to="records.to"
                :total="records.total"
            />
        </template>
    </main>
</template>
