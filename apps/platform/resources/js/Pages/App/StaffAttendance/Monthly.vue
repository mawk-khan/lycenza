<script setup lang="ts">
// ORG.9C-1 (ADR 0076 §69), HR-003: one month's staff attendance per employee,
// counted in days (two halves = one day) by the same state the daily register
// shows. Staff attendance records halves only -- there is no late, no-punch or
// loss-of-pay figure here, and leave is not split into paid and unpaid.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

type State = 'present' | 'absent' | 'leave' | 'holiday' | 'off_day' | 'unrecorded';

interface Props {
    summary: {
        month: string;
        from: string;
        to: string;
        calendarConfigured: boolean;
        rows: {
            employment: {
                employmentRecordId: string;
                employeeNumber: string | null;
                fullName: string | null;
                status: string;
            };
            halfDays: Record<State, number>;
        }[];
        totals: Record<State, number>;
    };
    currentMonth: string;
}

const props = defineProps<Props>();
const month = ref(props.summary.month);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const states: { key: State; label: string }[] = [
    { key: 'present', label: 'Present' },
    { key: 'absent', label: 'Absent' },
    { key: 'leave', label: 'On leave' },
    { key: 'holiday', label: 'Holiday' },
    { key: 'off_day', label: 'Off day' },
    { key: 'unrecorded', label: 'Not recorded' },
];

function days(halves: number): string {
    return halves === 0 ? '—' : String(halves / 2);
}

function apply(): void {
    router.get(
        '/app/staff-attendance/monthly',
        { month: month.value || undefined },
        { replace: true },
    );
}
</script>

<template>
    <Head title="Staff attendance by month" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/staff-attendance">← Staff attendance</a>
        <h1 class="mt-2 text-xl font-semibold">Staff attendance by month</h1>
        <p class="mt-1 text-sm text-slate-500">
            Days from {{ summary.from }} to {{ summary.to }}, per employee. Half days count as 0.5.
            Approved leave comes first, then what was recorded, then the staff calendar.
        </p>

        <form class="mt-6 flex items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="staff-month">Month</label>
                <input
                    id="staff-month"
                    v-model="month"
                    type="month"
                    :max="currentMonth"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                />
            </div>
        </form>
        <p v-if="pageErrors.month" class="mt-3 text-sm text-red-700" role="alert">
            {{ pageErrors.month }}
        </p>
        <p v-if="!summary.calendarConfigured" class="mt-3 text-sm text-amber-800">
            The staff calendar is not configured, so no day is shown as a holiday or off day.
        </p>

        <EmptyState
            v-if="summary.rows.length === 0"
            class="mt-6"
            title="No employment in this month"
            description="Nobody was employed during these days."
        />
        <div v-else class="relative mt-6 overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <caption class="sr-only">
                    Staff attendance days by state
                </caption>
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th
                            v-for="s in states"
                            :key="s.key"
                            scope="col"
                            class="py-2 text-right font-medium"
                        >
                            {{ s.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="row in summary.rows" :key="row.employment.employmentRecordId">
                        <td class="py-2">
                            {{ row.employment.fullName ?? '—' }}
                            <span class="text-xs text-slate-500">{{
                                row.employment.employeeNumber
                            }}</span>
                        </td>
                        <td v-for="s in states" :key="s.key" class="py-2 text-right font-mono">
                            {{ days(row.halfDays[s.key]) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 font-medium">
                        <td class="py-2">All employees</td>
                        <td v-for="s in states" :key="s.key" class="py-2 text-right font-mono">
                            {{ days(summary.totals[s.key]) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </main>
</template>
