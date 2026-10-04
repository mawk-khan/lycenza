<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import {
    halfLabel,
    summaryLabels,
    useAttendanceError,
    type HalfView,
} from '../../../staffAttendance';

/**
 * HRX.4 — My Attendance (staff self-service), READ ONLY.
 *
 * Your own daily staff attendance as your School recorded it, half by half,
 * with your approved leave, holidays and weekly offs shown alongside. You
 * cannot record or change attendance here; ask your School administrator.
 */
interface OwnDay {
    date: string;
    firstHalf: HalfView;
    secondHalf: HalfView;
    summary: string;
}

interface Props {
    available: boolean;
    today: string;
    attendance?: { from: string; to: string; calendarConfigured: boolean; days: OwnDay[] };
}

const props = defineProps<Props>();

const from = ref(props.attendance?.from ?? '');
const to = ref(props.attendance?.to ?? props.today);

function reload(): void {
    router.get(
        '/app/my-staff-attendance',
        { from: from.value, to: to.value },
        { preserveState: false },
    );
}

function weekday(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short' });
}

const attendanceError = useAttendanceError();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">My attendance</h1>

        <EmptyState
            v-if="!available"
            class="mt-8"
            title="No staff record for you in this School"
            description="Your account is not linked to a current employment here. Ask your School administrator."
        />
        <template v-else-if="attendance">
            <p class="mt-1 text-sm text-slate-500">
                Read only. Each half shows what your School recorded, or your approved leave, a
                holiday or a weekly off.
            </p>
            <p
                v-if="attendanceError"
                role="alert"
                class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
            >
                {{ attendanceError }}
            </p>
            <form class="mt-4 flex flex-wrap items-center gap-3" @submit.prevent="reload">
                <label class="text-sm text-slate-600" for="my-from">From</label>
                <input
                    id="my-from"
                    v-model="from"
                    type="date"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <label class="text-sm text-slate-600" for="my-to">To</label>
                <input
                    id="my-to"
                    v-model="to"
                    type="date"
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

            <EmptyState
                v-if="attendance.days.length === 0"
                class="mt-8"
                title="Nothing in this range"
                description="The range is outside your current employment."
            />
            <table v-else class="mt-6 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Date</th>
                        <th class="py-2">First half</th>
                        <th class="py-2">Second half</th>
                        <th class="py-2">Day</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="day in attendance.days"
                        :key="day.date"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">
                            {{ day.date }}
                            <span class="text-slate-500">{{ weekday(day.date) }}</span>
                        </td>
                        <td class="py-2">{{ halfLabel(day.firstHalf) }}</td>
                        <td class="py-2">{{ halfLabel(day.secondHalf) }}</td>
                        <td class="py-2">{{ summaryLabels[day.summary] ?? day.summary }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </main>
</template>
