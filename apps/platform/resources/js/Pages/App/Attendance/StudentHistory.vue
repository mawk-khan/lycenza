<script setup lang="ts">
import { formatCalendarDate } from '../../../datetime';
// ORG.9C-1 (ADR 0076 §69), PV1-05: one Student's attendance records over a date
// range, newest first -- the same records each register shows, with whether and
// why each was corrected (a closed reason, never free text). No rate, no
// percentage, no tally. Read-only.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

type Status = 'present' | 'absent' | 'late' | 'excused';

interface Row {
    id: string;
    sessionId: string | null;
    date: string | null;
    classLabel: string | null;
    subjectName: string | null;
    periodName: string | null;
    periodStartTime: string | null;
    status: Status;
    corrected: boolean;
    correctionReason: string | null;
    originalStatus: Status | null;
    correctedAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    student: { id: string; name: string };
    rows: { data: Row[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    filters: { from: string; to: string };
    today: string;
    correctionReasons: string[];
}

const props = defineProps<Props>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

const statusLabels: Record<Status, string> = {
    present: 'Present',
    absent: 'Absent',
    late: 'Late',
    excused: 'Excused',
};
const reasonLabels: Record<string, string> = {
    entered_in_error: 'entered in error',
    late_information: 'later information',
    administrative_review: 'administrative review',
};

function apply(): void {
    router.get(
        `/app/attendance/students/${props.student.id}`,
        { from: from.value || undefined, to: to.value || undefined },
        { preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head :title="`Attendance · ${student.name}`" />
    <main class="mx-auto max-w-5xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/attendance">← Attendance registers</a>
        <h1 class="mt-2 text-xl font-semibold">{{ student.name }} · attendance</h1>
        <p class="mt-1 text-sm text-slate-500">
            Every submitted register record for this Student in the range, newest first. Corrected
            records show the original status and the reason category.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="history-from">From</label>
                <input
                    id="history-from"
                    v-model="from"
                    type="date"
                    :max="today"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="history-to">To</label>
                <input
                    id="history-to"
                    v-model="to"
                    type="date"
                    :max="today"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <button type="submit" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                Show
            </button>
        </form>
        <p v-if="pageErrors.from || pageErrors.to" class="mt-3 text-sm text-red-700" role="alert">
            {{ pageErrors.from ?? pageErrors.to }}
        </p>

        <EmptyState
            v-if="rows.data.length === 0"
            class="mt-6"
            title="No records in this range"
            description="No submitted register recorded this Student on these dates."
        />
        <template v-else>
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <caption class="sr-only">
                        Attendance records of one Student
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Date</th>
                            <th scope="col" class="py-2 font-medium">Class</th>
                            <th scope="col" class="py-2 font-medium">Period</th>
                            <th scope="col" class="py-2 font-medium">Subject</th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in rows.data" :key="row.id">
                            <td class="py-2">
                                <a
                                    v-if="row.sessionId"
                                    class="underline"
                                    :href="`/app/attendance/${row.sessionId}`"
                                    >{{ formatCalendarDate(row.date) }}</a
                                >
                                <span v-else>{{ formatCalendarDate(row.date) }}</span>
                            </td>
                            <td class="py-2 text-slate-600">{{ row.classLabel ?? '—' }}</td>
                            <td class="py-2 text-slate-600">{{ row.periodName ?? '—' }}</td>
                            <td class="py-2 text-slate-600">{{ row.subjectName ?? '—' }}</td>
                            <td class="py-2">
                                {{ statusLabels[row.status] }}
                                <span v-if="row.corrected" class="block text-xs text-slate-500">
                                    corrected from
                                    {{
                                        row.originalStatus ? statusLabels[row.originalStatus] : '—'
                                    }}
                                    ({{
                                        row.correctionReason
                                            ? (reasonLabels[row.correctionReason] ??
                                              row.correctionReason)
                                            : '—'
                                    }})
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </template>
    </main>
</template>
