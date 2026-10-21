<script setup lang="ts">
// UAT-C3 (UAT-FIN-08): Students who still owe money, for follow-up. Computed
// on read from charges, payments and concessions (no stored balance).
// Overdue = owed on charges due before the School's today; a charge with no
// due date is outstanding, never overdue. Amounts only; no headcounts.
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface Row {
    studentId: string;
    name: string;
    studentNumber: string | null;
    class: string | null;
    // LC-4 (SB-03b): marked inactive -- has left the School.
    leaver: boolean;
    outstanding: string;
    overdue: string;
    openCharges: number;
    earliestOverdueDueDate: string | null;
    nextDueDate: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    rows: { data: Row[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    totals: { outstanding: string; overdue: string; currency: string };
    today: string;
    filters: {
        status: 'outstanding' | 'overdue';
        academic_year_id: string;
        q: string;
        grade_level_id: string;
        section_id: string;
        standing: '' | 'current' | 'leavers';
        campus_id: string;
    };
    campuses: { id: string; name: string }[];
    academicYears: { id: string; name: string }[];
    canSeeClass: boolean;
    gradeLevels: { id: string; name: string }[];
    sections: { id: string; label: string; gradeLevelId: string }[];
}

const props = defineProps<Props>();

const status = ref(props.filters.status);
const yearId = ref(props.filters.academic_year_id);
const q = ref(props.filters.q);
const gradeId = ref(props.filters.grade_level_id);
const sectionId = ref(props.filters.section_id);
const standing = ref(props.filters.standing);
const campusId = ref(props.filters.campus_id);

const emptyTitle = computed(() =>
    props.filters.standing === 'leavers'
        ? 'No leavers owe money for these filters'
        : props.filters.campus_id
          ? 'No outstanding balances for this Campus'
          : 'Nobody owes money for these filters',
);

const sectionsForGrade = computed(() =>
    gradeId.value ? props.sections.filter((s) => s.gradeLevelId === gradeId.value) : props.sections,
);

function apply(): void {
    if (sectionId.value && !sectionsForGrade.value.some((s) => s.id === sectionId.value)) {
        sectionId.value = '';
    }
    router.get(
        '/app/finance/outstanding',
        {
            status: status.value === 'outstanding' ? undefined : status.value,
            academic_year_id: yearId.value || undefined,
            q: q.value.trim() || undefined,
            grade_level_id: gradeId.value || undefined,
            section_id: sectionId.value || undefined,
            standing: standing.value || undefined,
            campus_id: campusId.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Outstanding fees" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Outstanding fees</h1>
        <p class="mt-1 text-sm text-slate-500">
            Students who still owe money, most overdue first. Overdue means the charge's due date is
            before today ({{ today }}). Open a Student's statement to see each charge.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="filter-status">Show</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="outstanding">Everyone who owes money</option>
                    <option value="overdue">Only overdue</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="filter-year">Academic year</label>
                <select
                    id="filter-year"
                    v-model="yearId"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">All years</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="filter-standing">Students</label>
                <select
                    id="filter-standing"
                    v-model="standing"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">All Students</option>
                    <option value="current">Current Students</option>
                    <option value="leavers">Leavers (marked inactive)</option>
                </select>
            </div>
            <div v-if="campuses.length > 0">
                <label class="block text-xs text-slate-600" for="filter-campus"
                    >Charge campus</label
                >
                <select
                    id="filter-campus"
                    v-model="campusId"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                >
                    <option value="">All campuses</option>
                    <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
                    <option value="unavailable">Whole school (no campus)</option>
                </select>
            </div>
            <template v-if="canSeeClass">
                <div>
                    <label class="block text-xs text-slate-600" for="filter-grade">Grade</label>
                    <select
                        id="filter-grade"
                        v-model="gradeId"
                        class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                        @change="apply"
                    >
                        <option value="">All grades</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-600" for="filter-section">Section</label>
                    <select
                        id="filter-section"
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
            </template>
            <div>
                <label class="block text-xs text-slate-600" for="filter-q">Student</label>
                <input
                    id="filter-q"
                    v-model="q"
                    type="search"
                    placeholder="Name or student number"
                    class="mt-1 w-56 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <button type="submit" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                Search
            </button>
        </form>

        <dl class="mt-6 grid grid-cols-2 gap-4 sm:max-w-md">
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Outstanding (these filters)</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totals.outstanding, totals.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Of which overdue</dt>
                <dd class="text-lg font-semibold text-red-700">
                    {{ formatMoney(totals.overdue, totals.currency) }}
                </dd>
            </div>
        </dl>

        <p v-if="filters.campus_id" class="mt-3 text-xs text-slate-500">
            Each charge keeps the campus it was recorded against when it was charged (a fee run's
            placement, a canteen outlet, a library loan, or a manual charge's placement on that
            day). It never follows the Student's current campus. Charges with no campus are under
            "Whole school (no campus)".
        </p>

        <EmptyState
            v-if="rows.data.length === 0"
            class="mt-6"
            :title="emptyTitle"
            :description="
                filters.standing === 'leavers' && (filters.grade_level_id || filters.section_id)
                    ? 'Leavers have no current class, so a Grade or Section filter excludes them.'
                    : 'Every matching charge is paid, covered by a concession or cancelled.'
            "
        />

        <template v-else>
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <caption class="sr-only">
                        Students who owe money
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th v-if="canSeeClass" scope="col" class="py-2 font-medium">Class</th>
                            <th scope="col" class="py-2 text-right font-medium">Outstanding</th>
                            <th scope="col" class="py-2 text-right font-medium">Overdue</th>
                            <th scope="col" class="py-2 pl-4 font-medium">Due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in rows.data" :key="row.studentId">
                            <td class="py-2">
                                <a
                                    class="underline"
                                    :href="`/app/finance/fee-statements/${row.studentId}`"
                                    >{{ row.name }}</a
                                >
                                <span class="block text-xs text-slate-500"
                                    >{{ row.studentNumber
                                    }}<span
                                        v-if="row.leaver"
                                        class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-slate-700"
                                        >Left (inactive)</span
                                    ></span
                                >
                            </td>
                            <td v-if="canSeeClass" class="py-2 text-slate-600">
                                {{ row.class ?? '—' }}
                            </td>
                            <td class="py-2 text-right font-mono">
                                {{ formatMoney(row.outstanding, totals.currency) }}
                            </td>
                            <td
                                class="py-2 text-right font-mono"
                                :class="row.overdue !== '0.00' ? 'text-red-700' : 'text-slate-400'"
                            >
                                {{ formatMoney(row.overdue, totals.currency) }}
                            </td>
                            <td class="py-2 pl-4 text-xs text-slate-600">
                                <span v-if="row.earliestOverdueDueDate" class="block text-red-700"
                                    >Overdue since {{ row.earliestOverdueDueDate }}</span
                                >
                                <span v-if="row.nextDueDate" class="block"
                                    >Next due {{ row.nextDueDate }}</span
                                >
                                <span class="block text-slate-400"
                                    >{{ row.openCharges }} open charge{{
                                        row.openCharges === 1 ? '' : 's'
                                    }}</span
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
