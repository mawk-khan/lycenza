<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

/**
 * Submitted registers. Every class-identity field below is rendered
 * from the AttendanceSession's own IMMUTABLE snapshot -- in particular
 * periodStartTime/periodEndTime are the historical wall-clock times
 * stored on the register, never the current TimetablePeriod's, which
 * can legitimately be changed later.
 */
interface SessionRow {
    id: string;
    attendanceDate: string;
    sectionCode: string | null;
    subjectName: string | null;
    teacherName: string | null;
    periodName: string | null;
    periodStartTime: string;
    periodEndTime: string;
    recordCount: number;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    sessions: { data: SessionRow[]; links: PageLink[]; total: number };
    filters: { attendanceDate: string };
    canManage: boolean;
    // TCH.4: the same page serves "My Attendance" (the owned teacher
    // surface) under its own URL; the server decides what it contains.
    baseUrl?: string;
    heading?: string;
}

const props = withDefaults(defineProps<Props>(), {
    baseUrl: '/app/attendance',
    heading: 'Attendance registers',
});

const attendanceDate = ref(props.filters.attendanceDate);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

watch(attendanceDate, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            props.baseUrl,
            { attendance_date: attendanceDate.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ heading }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Submitted class registers. A register is taken once, complete, and thereafter
                    only individual statuses are corrected.
                </p>
            </div>
            <a
                v-if="canManage"
                :href="`${baseUrl}/take`"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Take a register
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-date">Date</label>
            <input
                id="filter-date"
                v-model="attendanceDate"
                type="date"
                class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="sessions.data.length === 0"
            class="mt-6"
            title="No registers yet"
            description="Take the first register to begin recording Student attendance."
        >
            <template v-if="canManage" #action>
                <a
                    :href="`${baseUrl}/take`"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Take a register
                </a>
            </template>
        </EmptyState>

        <table v-else class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Date</th>
                    <th class="py-2">Section</th>
                    <th class="py-2">Subject</th>
                    <th class="py-2">Period</th>
                    <th class="py-2">Teacher</th>
                    <th class="py-2">Students</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in sessions.data" :key="row.id" class="border-b border-slate-100">
                    <td class="py-2">{{ row.attendanceDate }}</td>
                    <td class="py-2">{{ row.sectionCode }}</td>
                    <td class="py-2">{{ row.subjectName }}</td>
                    <td class="py-2">
                        {{ row.periodName }}
                        <span class="text-slate-500">
                            ({{ row.periodStartTime }}–{{ row.periodEndTime }})
                        </span>
                    </td>
                    <td class="py-2">{{ row.teacherName }}</td>
                    <td class="py-2">{{ row.recordCount }}</td>
                    <td class="py-2 text-right">
                        <a class="underline" :href="`${baseUrl}/${row.id}`">View</a>
                    </td>
                </tr>
            </tbody>
        </table>

        <Pagination class="mt-6" :links="sessions.links" />
    </main>
</template>
