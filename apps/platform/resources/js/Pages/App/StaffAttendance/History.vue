<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    employmentName,
    halfLabel,
    halfStatusLabel,
    reasonLabels,
    summaryLabels,
    useAttendanceError,
    type AttendanceDay,
    type Correction,
    type EmploymentLabel,
} from '../../../staffAttendance';

/**
 * HRX.3 — one employment's staff attendance over a date range, with every
 * correction kept as history (before and after of both halves, a closed
 * reason, the time). Read-only: records are changed from the daily register.
 */
interface Props {
    history: {
        employment: EmploymentLabel;
        from: string;
        to: string;
        calendarConfigured: boolean;
        days: AttendanceDay[];
    };
    corrections: Record<string, Correction[]>;
    today: string;
    reasons: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const from = ref(props.history.from);
const to = ref(props.history.to);

function reload(): void {
    router.get(
        '/app/staff-attendance/history',
        {
            employment_record_id: props.history.employment.employmentRecordId,
            from: from.value,
            to: to.value,
        },
        { preserveState: false },
    );
}

function weekday(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short' });
}

const attendanceError = useAttendanceError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/staff-attendance">← Daily register</a>
        <h1 class="mt-2 text-xl font-semibold">
            Attendance history · {{ employmentName(history.employment) }}
        </h1>
        <p
            v-if="attendanceError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ attendanceError }}
        </p>

        <form class="mt-4 flex flex-wrap items-center gap-3" @submit.prevent="reload">
            <label class="text-sm text-slate-600" for="history-from">From</label>
            <input
                id="history-from"
                v-model="from"
                type="date"
                class="rounded border border-slate-300 px-3 py-2 text-sm"
            />
            <label class="text-sm text-slate-600" for="history-to">To</label>
            <input
                id="history-to"
                v-model="to"
                type="date"
                :max="today"
                class="rounded border border-slate-300 px-3 py-2 text-sm"
            />
            <button
                type="submit"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Show
            </button>
            <span class="text-sm text-slate-500">At most 93 days.</span>
        </form>

        <table class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Date</th>
                    <th class="py-2">First half</th>
                    <th class="py-2">Second half</th>
                    <th class="py-2">Day</th>
                    <th class="py-2">Corrections</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="day in history.days"
                    :key="day.date"
                    class="border-b border-slate-100 align-top"
                >
                    <td class="py-2">
                        <a class="underline" :href="`/app/staff-attendance?date=${day.date}`">{{
                            day.date
                        }}</a>
                        <span class="ml-1 text-slate-500">{{ weekday(day.date) }}</span>
                    </td>
                    <td class="py-2">{{ halfLabel(day.firstHalf) }}</td>
                    <td class="py-2">{{ halfLabel(day.secondHalf) }}</td>
                    <td class="py-2">{{ summaryLabels[day.summary] ?? day.summary }}</td>
                    <td class="py-2">
                        <ul v-if="day.record && corrections[day.record.id]" class="space-y-1">
                            <li
                                v-for="c in corrections[day.record.id]"
                                :key="c.id"
                                class="text-xs text-slate-600"
                            >
                                v{{ c.fromVersion }}→v{{ c.toVersion }}:
                                {{ halfStatusLabel(c.before.firstHalf) }} /
                                {{ halfStatusLabel(c.before.secondHalf) }} →
                                {{ halfStatusLabel(c.after.firstHalf) }} /
                                {{ halfStatusLabel(c.after.secondHalf) }} ·
                                {{ reasonLabels[c.reasonCode] ?? c.reasonCode }} ·
                                {{ c.correctedAt.slice(0, 10) }}
                            </li>
                        </ul>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
