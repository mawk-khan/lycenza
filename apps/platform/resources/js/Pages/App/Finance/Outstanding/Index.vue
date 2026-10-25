<script setup lang="ts">
// UAT-C3 (UAT-FIN-08): Students who still owe money, for follow-up. Computed
// on read from charges, payments and concessions (no stored balance).
// Overdue = owed on charges due before the School's today; a charge with no
// due date is outstanding, never overdue. Amounts only; no headcounts.
// ORG.9B (ADR 0076 §68): billed - concession - paid = balance per Student
// (uncancelled charges only), totals over every matching row (not one page),
// and an optional "as at" School-local date (figures as recorded by the end of
// that day; overdue judged against it).
import { Head, router, usePage } from '@inertiajs/vue3';
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
    billed: string;
    concession: string;
    paid: string;
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
    totals: {
        billed: string;
        concession: string;
        paid: string;
        outstanding: string;
        overdue: string;
        currency: string;
    };
    today: string;
    asAt: string | null;
    filters: {
        status: 'outstanding' | 'overdue' | 'all';
        academic_year_id: string;
        q: string;
        grade_level_id: string;
        section_id: string;
        standing: '' | 'current' | 'leavers';
        campus_id: string;
        as_at: string;
    };
    campuses: { id: string; name: string }[];
    academicYears: { id: string; name: string }[];
    canSeeClass: boolean;
    gradeLevels: { id: string; name: string }[];
    sections: { id: string; label: string; gradeLevelId: string }[];
    // ORG.9D-2: may also prepare a communication (presentation only).
    canSendFeeReminder: boolean;
}

const props = defineProps<Props>();

const status = ref(props.filters.status);
const yearId = ref(props.filters.academic_year_id);
const q = ref(props.filters.q);
const gradeId = ref(props.filters.grade_level_id);
const sectionId = ref(props.filters.section_id);
const standing = ref(props.filters.standing);
const campusId = ref(props.filters.campus_id);
const asAt = ref(props.filters.as_at);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

const emptyTitle = computed(() =>
    props.filters.standing === 'leavers'
        ? 'No leavers owe money for these filters'
        : props.filters.campus_id
          ? 'No outstanding balances for this Campus'
          : props.filters.status === 'all'
            ? 'No charges for these filters'
            : 'Nobody owes money for these filters',
);

const sectionsForGrade = computed(() =>
    gradeId.value ? props.sections.filter((s) => s.gradeLevelId === gradeId.value) : props.sections,
);

// ORG.9D-2 (ADR 0076 §74): a fee reminder starts from this page's own
// filters -- the balance status, year, charge campus and class. Search, the
// Students (current/leavers) filter and "as at" are not part of a reminder:
// it always reminds every Student in the selection who owes money NOW.
const reminderHref = computed(() => {
    const params = new URLSearchParams({ audience: 'fee_reminder' });
    params.set('status', props.filters.status === 'overdue' ? 'overdue' : 'outstanding');
    for (const key of ['academic_year_id', 'campus_id', 'section_id'] as const) {
        if (props.filters[key]) {
            params.set(key, props.filters[key]);
        }
    }
    // A Section already names its Grade; a reminder carries one class filter.
    if (props.filters.grade_level_id && !props.filters.section_id) {
        params.set('grade_level_id', props.filters.grade_level_id);
    }
    return `/app/communications/announcements/create?${params.toString()}`;
});

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
            as_at: asAt.value || undefined,
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
            before {{ asAt ? `the as-at date (${asAt})` : `today (${today})` }}. Open a Student's
            statement to see each charge.
        </p>
        <p v-if="asAt" class="mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
            As at the end of {{ asAt }} (school time): charges, concessions and payments as recorded
            by then. A payment recorded later counts from the day it was recorded. Class and
            "Students" (current or leavers) describe each Student today, not on that date.
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
                    <option value="all">Everyone with charges (paid up included)</option>
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
                <label class="block text-xs text-slate-600" for="filter-as-at">As at</label>
                <input
                    id="filter-as-at"
                    v-model="asAt"
                    type="date"
                    :max="today"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="apply"
                />
            </div>
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

        <p v-if="pageErrors.as_at" class="mt-3 text-sm text-red-700" role="alert">
            {{ pageErrors.as_at }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Billed (all matching rows)</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totals.billed, totals.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Concessions</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totals.concession, totals.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Paid</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totals.paid, totals.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-xs text-slate-500">Balance (all matching rows)</dt>
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

        <section
            v-if="canSendFeeReminder && !asAt"
            class="mt-4 flex flex-wrap items-center gap-3 rounded border border-slate-200 p-3 text-sm"
        >
            <a class="rounded bg-slate-900 px-3 py-1.5 text-white" :href="reminderHref"
                >Send fee reminder</a
            >
            <span class="text-xs text-slate-600"
                >Emails the current guardians of every Student in this selection who owes money,
                after approval. Uses the status, year, campus and class filters above (not the
                search or Students filters); amounts are read again when it is sent.</span
            >
        </section>

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
                    : filters.status === 'all'
                      ? 'No uncancelled charge matches these filters.'
                      : 'Every matching charge is paid, covered by a concession or cancelled.'
            "
        />

        <template v-else>
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-left text-sm">
                    <caption class="sr-only">
                        Students with billed, concession, paid and balance amounts
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th v-if="canSeeClass" scope="col" class="py-2 font-medium">Class</th>
                            <th scope="col" class="py-2 text-right font-medium">Billed</th>
                            <th scope="col" class="py-2 text-right font-medium">Concession</th>
                            <th scope="col" class="py-2 text-right font-medium">Paid</th>
                            <th scope="col" class="py-2 text-right font-medium">Balance</th>
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
                                {{ formatMoney(row.billed, totals.currency) }}
                            </td>
                            <td class="py-2 text-right font-mono text-slate-600">
                                {{ formatMoney(row.concession, totals.currency) }}
                            </td>
                            <td class="py-2 text-right font-mono text-slate-600">
                                {{ formatMoney(row.paid, totals.currency) }}
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
