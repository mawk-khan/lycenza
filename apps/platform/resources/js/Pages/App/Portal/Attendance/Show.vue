<script setup lang="ts">
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * POR.2 — one child's attendance, read-only: date, period and status, for the
 * current academic year only (at most `maxDays` days per view).
 */
interface AttendanceRow {
    date: string;
    periodStart: string;
    periodEnd: string;
    status: string;
}

interface Props {
    schoolName: string;
    students: { id: string; name: string }[];
    maxDays: number;
    attendance: {
        student: { id: string; name: string };
        academicYear: { id: string; name: string; startsOn: string; endsOn: string } | null;
        window: { from: string; to: string } | null;
        records: AttendanceRow[];
    };
}

const props = defineProps<Props>();

const from = ref(props.attendance.window?.from ?? '');
const to = ref(props.attendance.window?.to ?? '');

const statusLabels: Record<string, string> = {
    present: 'Present',
    absent: 'Absent',
    late: 'Late',
    excused: 'Excused',
};

function rangeUrl(): string {
    const params = new URLSearchParams();
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);
    return `/app/portal/attendance/students/${props.attendance.student.id}?${params.toString()}`;
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app/portal/attendance">Back</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">
            Attendance · {{ attendance.student.name }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ schoolName }}
            <template v-if="attendance.academicYear">
                · {{ attendance.academicYear.name }}</template
            >
        </p>

        <nav
            v-if="students.length > 1"
            class="mt-4 flex flex-wrap gap-3 text-sm"
            aria-label="Your students"
        >
            <a
                v-for="student in students"
                :key="student.id"
                :class="student.id === attendance.student.id ? 'font-semibold' : 'underline'"
                :href="`/app/portal/attendance/students/${student.id}`"
                >{{ student.name }}</a
            >
        </nav>

        <form
            v-if="attendance.academicYear"
            class="mt-6 flex flex-wrap items-end gap-3 text-sm"
            @submit.prevent
        >
            <label class="block">
                <span class="block text-xs text-slate-500">From</span>
                <input
                    v-model="from"
                    type="date"
                    class="rounded border border-slate-300 px-2 py-1"
                />
            </label>
            <label class="block">
                <span class="block text-xs text-slate-500">To</span>
                <input v-model="to" type="date" class="rounded border border-slate-300 px-2 py-1" />
            </label>
            <a class="underline" :href="rangeUrl()">Show</a>
            <span class="text-xs text-slate-500"
                >This academic year only; up to {{ maxDays }} days at a time.</span
            >
        </form>

        <EmptyState
            v-if="attendance.records.length === 0"
            class="mt-6"
            title="No attendance recorded"
            description="Nothing is recorded for these dates in the current academic year."
        />

        <table v-else class="mt-6 w-full text-left text-sm">
            <thead class="text-xs text-slate-500">
                <tr>
                    <th class="py-2">Date</th>
                    <th class="py-2">Period</th>
                    <th class="py-2">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="(record, index) in attendance.records" :key="index">
                    <td class="py-2">{{ record.date }}</td>
                    <td class="py-2">{{ record.periodStart }}–{{ record.periodEnd }}</td>
                    <td class="py-2">{{ statusLabels[record.status] ?? record.status }}</td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
