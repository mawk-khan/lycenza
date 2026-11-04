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
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">My attendance</h1>

        <EmptyState
            v-if="!available"
            class="mt-8"
            title="No staff record for you in this School"
            description="Your account is not linked to a current employment here. Ask your School administrator."
        />
        <template v-else-if="attendance">
            <p class="mt-1 text-sm text-ink-muted">
                Read only. Each half shows what your School recorded, or your approved leave, a
                holiday or a weekly off.
            </p>
            <p
                v-if="attendanceError"
                role="alert"
                class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
            >
                {{ attendanceError }}
            </p>
            <form class="mt-4 flex flex-wrap items-center gap-3" @submit.prevent="reload">
                <label class="text-sm text-ink-muted" for="my-from">From</label>
                <input id="my-from" v-model="from" type="date" class="input w-auto" />
                <label class="text-sm text-ink-muted" for="my-to">To</label>
                <input id="my-to" v-model="to" type="date" class="input w-auto" />
                <button type="submit" class="btn btn-primary">Show</button>
                <span class="text-sm text-ink-muted">At most 93 days.</span>
            </form>

            <EmptyState
                v-if="attendance.days.length === 0"
                class="mt-8"
                title="Nothing in this range"
                description="The range is outside your current employment."
            />
            <table v-else class="mt-6 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-2">Date</th>
                        <th class="py-2">First half</th>
                        <th class="py-2">Second half</th>
                        <th class="py-2">Day</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="day in attendance.days" :key="day.date" class="border-b border-line">
                        <td class="py-2">
                            {{ day.date }}
                            <span class="text-ink-muted">{{ weekday(day.date) }}</span>
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
