<script setup lang="ts">
// ORG.9C-1 (ADR 0076 §69), PV1-05: which of a day's timetabled classes have a
// submitted register. Counts are of registers, never of people. There is no
// "missing" status: Lycenza does not record school holidays or cancelled
// periods for Students, so on a day the school was closed every class shows
// as not submitted. Read-only.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

type Status = 'submitted' | 'pending' | 'not_submitted';

interface Row {
    timetableEntryId: string;
    classLabel: string | null;
    subjectName: string | null;
    teacherName: string | null;
    periodName: string | null;
    periodStartTime: string | null;
    periodEndTime: string | null;
    status: Status;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    date: string;
    today: string;
    applicable: boolean;
    rows: { data: Row[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    counts: Record<Status, number>;
    options: {
        grades: { id: string; name: string }[];
        sections: { id: string; label: string; gradeLevelId: string }[];
    };
    filters: { grade_level_id: string; section_id: string; status: string };
    statuses: Status[];
}

const props = defineProps<Props>();

const date = ref(props.date);
const gradeId = ref(props.filters.grade_level_id);
const sectionId = ref(props.filters.section_id);
const status = ref(props.filters.status);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

const labels: Record<Status, string> = {
    submitted: 'Submitted',
    pending: 'Not yet due (period still running or later today)',
    not_submitted: 'Not submitted',
};
const badge: Record<Status, string> = {
    submitted: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    pending: 'bg-slate-50 text-slate-700 ring-slate-200',
    not_submitted: 'bg-amber-50 text-amber-900 ring-amber-200',
};

const sectionsForGrade = computed(() =>
    gradeId.value
        ? props.options.sections.filter((s) => s.gradeLevelId === gradeId.value)
        : props.options.sections,
);

function apply(): void {
    if (sectionId.value && !sectionsForGrade.value.some((s) => s.id === sectionId.value)) {
        sectionId.value = '';
    }
    router.get(
        '/app/attendance/registers',
        {
            date: date.value || undefined,
            grade_level_id: gradeId.value || undefined,
            section_id: sectionId.value || undefined,
            status: status.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
}

function period(row: Row): string {
    const time =
        row.periodStartTime && row.periodEndTime
            ? ` (${row.periodStartTime.slice(0, 5)}–${row.periodEndTime.slice(0, 5)})`
            : '';
    return `${row.periodName ?? '—'}${time}`;
}
</script>

<template>
    <Head title="Registers by day" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/attendance">← Attendance registers</a>
        <h1 class="mt-2 text-xl font-semibold">Registers taken and not taken</h1>
        <p class="mt-1 text-sm text-slate-500">
            The classes on the current timetable for the chosen day, and whether each register has
            been submitted. Lycenza does not record school holidays or cancelled periods, so on a
            day the school was closed every class shows as not submitted.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="registers-date">Date</label>
                <input
                    id="registers-date"
                    v-model="date"
                    type="date"
                    :max="today"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="registers-grade">Grade</label>
                <select
                    id="registers-grade"
                    v-model="gradeId"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">All grades</option>
                    <option v-for="g in options.grades" :key="g.id" :value="g.id">
                        {{ g.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="registers-section">Section</label>
                <select
                    id="registers-section"
                    v-model="sectionId"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">All sections</option>
                    <option v-for="s in sectionsForGrade" :key="s.id" :value="s.id">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="registers-status">Status</label>
                <select
                    id="registers-status"
                    v-model="status"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">Any status</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ labels[s] }}</option>
                </select>
            </div>
        </form>
        <p v-if="pageErrors.date" class="mt-3 text-sm text-red-700" role="alert">
            {{ pageErrors.date }}
        </p>

        <dl v-if="applicable" class="mt-6 grid grid-cols-3 gap-4 sm:max-w-xl">
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Submitted registers</dt>
                <dd class="text-lg font-semibold">{{ counts.submitted }}</dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Not submitted</dt>
                <dd class="text-lg font-semibold">{{ counts.not_submitted }}</dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Not yet due</dt>
                <dd class="text-lg font-semibold">{{ counts.pending }}</dd>
            </div>
        </dl>

        <EmptyState
            v-if="!applicable"
            class="mt-6"
            title="No register work on this day"
            description="The day is outside the active academic year, or no academic year is active."
        />
        <EmptyState
            v-else-if="rows.data.length === 0"
            class="mt-6"
            title="No classes match"
            description="No class on the current timetable meets on this weekday for these filters."
        />
        <template v-else>
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <caption class="sr-only">
                        Timetabled classes and their register status
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Class</th>
                            <th scope="col" class="py-2 font-medium">Period</th>
                            <th scope="col" class="py-2 font-medium">Subject</th>
                            <th scope="col" class="py-2 font-medium">Teacher</th>
                            <th scope="col" class="py-2 font-medium">Register</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in rows.data" :key="row.timetableEntryId">
                            <td class="py-2">{{ row.classLabel ?? '—' }}</td>
                            <td class="py-2 text-slate-600">{{ period(row) }}</td>
                            <td class="py-2 text-slate-600">{{ row.subjectName ?? '—' }}</td>
                            <td class="py-2 text-slate-600">{{ row.teacherName ?? '—' }}</td>
                            <td class="py-2">
                                <span
                                    class="inline-flex rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="badge[row.status]"
                                    >{{ labels[row.status] }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </template>
    </main>
</template>
