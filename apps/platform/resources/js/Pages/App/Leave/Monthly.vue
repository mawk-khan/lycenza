<script setup lang="ts">
// ORG.9C-1 (ADR 0076 §69), HR-042: approved leave taken in one month, per
// employee and leave type, in days (two half-day units = one day). A request
// spanning months counts only its days in this month. No reasons are shown and
// leave is not split into paid and unpaid. Read-only.
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { employeeName, type EmployeeLabel, type LeaveTypeRow } from '../../../leave';

interface Props {
    summary: {
        month: string;
        rows: { employmentRecordId: string; units: Record<string, number>; total: number }[];
        totals: Record<string, number>;
    };
    employees: Record<string, EmployeeLabel>;
    types: LeaveTypeRow[];
}

const props = defineProps<Props>();
const month = ref(props.summary.month);
const usedTypes = computed(() => props.types.filter((t) => t.id in props.summary.totals));

function days(units: number | undefined): string {
    return units ? String(units / 2) : '—';
}

function apply(): void {
    router.get('/app/leave/monthly', { month: month.value || undefined }, { replace: true });
}
</script>

<template>
    <Head title="Leave by month" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave taken by month</h1>
        <p class="mt-1 text-sm text-slate-500">
            Approved leave days in the month, per employee and leave type. Half days count as 0.5.
        </p>

        <form class="mt-6 flex items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="leave-month">Month</label>
                <input
                    id="leave-month"
                    v-model="month"
                    type="month"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                />
            </div>
        </form>

        <EmptyState
            v-if="summary.rows.length === 0"
            class="mt-6"
            title="No approved leave in this month"
            description="Submitted, rejected, withdrawn and cancelled requests are not counted."
        />
        <div v-else class="relative mt-6 overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <caption class="sr-only">
                    Approved leave days by employee and leave type
                </caption>
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th
                            v-for="t in usedTypes"
                            :key="t.id"
                            scope="col"
                            class="py-2 text-right font-medium"
                        >
                            {{ t.name }}
                        </th>
                        <th scope="col" class="py-2 text-right font-medium">Total days</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="row in summary.rows" :key="row.employmentRecordId">
                        <td class="py-2">{{ employeeName(employees, row.employmentRecordId) }}</td>
                        <td v-for="t in usedTypes" :key="t.id" class="py-2 text-right font-mono">
                            {{ days(row.units[t.id]) }}
                        </td>
                        <td class="py-2 text-right font-mono">{{ days(row.total) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 font-medium">
                        <td class="py-2">All employees</td>
                        <td v-for="t in usedTypes" :key="t.id" class="py-2 text-right font-mono">
                            {{ days(summary.totals[t.id]) }}
                        </td>
                        <td class="py-2 text-right font-mono">
                            {{ days(Object.values(summary.totals).reduce((a, b) => a + b, 0)) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </main>
</template>
