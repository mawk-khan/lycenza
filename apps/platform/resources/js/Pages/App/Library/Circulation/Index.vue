<script setup lang="ts">
import { formatCalendarDate } from '../../../../datetime';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Pagination from '../../../../Components/Pagination.vue';
import EmptyState from '../../../../Components/EmptyState.vue';

interface LoanRow {
    id: string;
    copyCode: string;
    titleName: string;
    studentName: string;
    checkedOutAt: string;
    dueAt: string;
    isOverdue: boolean;
    returnedAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    loans: {
        data: LoanRow[];
        links: PageLink[];
        total: number;
    };
    status: 'active' | 'returned' | 'all';
    // ORG.9C-1 (ADR 0076 §69): narrowing filters only (dates, Student, Campus).
    filters: { from: string; to: string; student: string; campus_id: string };
    campuses: { id: string; name: string }[];
    canManage: boolean;
}

const props = defineProps<Props>();

const views = [
    { status: 'active', label: 'Active loans', href: '/app/library/circulation' },
    {
        status: 'returned',
        label: 'Returned loans',
        href: '/app/library/circulation?status=returned',
    },
    { status: 'all', label: 'All loans', href: '/app/library/circulation?status=all' },
] as const;

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const student = ref(props.filters.student);
const campusId = ref(props.filters.campus_id);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const dateLabel = computed(() => (props.status === 'returned' ? 'Returned' : 'Issued'));

function applyFilters(): void {
    router.get(
        '/app/library/circulation',
        {
            status: props.status === 'active' ? undefined : props.status,
            from: from.value || undefined,
            to: to.value || undefined,
            student: student.value.trim() || undefined,
            campus_id: campusId.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
}

function checkIn(loanId: string): void {
    router.post(`/app/library/circulation/${loanId}/check-in`);
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Library circulation</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{
                        status === 'active'
                            ? 'Currently active loans.'
                            : status === 'returned'
                              ? 'Returned loans, most recently returned first. Open a loan to see its fine, if any.'
                              : 'Every loan, most recently issued first.'
                    }}
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/library/circulation/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Check out an item
            </a>
        </div>

        <nav aria-label="Loan status" class="mt-4 border-b border-slate-200">
            <ul class="flex gap-4 text-sm">
                <li v-for="view in views" :key="view.status">
                    <a
                        :href="view.href"
                        :aria-current="status === view.status ? 'page' : undefined"
                        class="-mb-px inline-block border-b-2 px-1 py-2 focus-visible:outline-2 focus-visible:outline-offset-2"
                        :class="
                            status === view.status
                                ? 'border-slate-900 font-medium text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        >{{ view.label }}</a
                    >
                </li>
            </ul>
        </nav>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-xs text-slate-600" for="loans-from"
                    >{{ dateLabel }} from</label
                >
                <input
                    id="loans-from"
                    v-model="from"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="loans-to"
                    >{{ dateLabel }} to</label
                >
                <input
                    id="loans-to"
                    v-model="to"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="loans-student">Student</label>
                <input
                    id="loans-student"
                    v-model="student"
                    type="search"
                    placeholder="Name or student number"
                    class="mt-1 w-52 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <div v-if="campuses.length > 1">
                <label class="block text-xs text-slate-600" for="loans-campus">Campus</label>
                <select
                    id="loans-campus"
                    v-model="campusId"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                >
                    <option value="">All campuses</option>
                    <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <button type="submit" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                Filter
            </button>
        </form>
        <p v-if="pageErrors.to || pageErrors.from" class="mt-2 text-sm text-red-700" role="alert">
            {{ pageErrors.to ?? pageErrors.from }}
        </p>

        <EmptyState
            v-if="loans.data.length === 0"
            class="mt-6"
            :title="
                status === 'active'
                    ? 'No active loans'
                    : status === 'returned'
                      ? 'No returned loans'
                      : 'No loans'
            "
            :description="
                status === 'active'
                    ? 'Check out a Library item to a Student to see it here.'
                    : 'A loan appears here once it is checked in.'
            "
        >
            <template v-if="canManage && status === 'active'" #action>
                <a
                    href="/app/library/circulation/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Check out an item
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <div class="relative overflow-x-auto mt-6">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        {{
                            status === 'active'
                                ? 'Active loans'
                                : status === 'returned'
                                  ? 'Returned loans'
                                  : 'All loans'
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="pr-3 py-2 font-medium">Title</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Copy</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Student</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Issued</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Due</th>
                            <th
                                v-if="status !== 'active'"
                                scope="col"
                                class="pr-3 py-2 font-medium"
                            >
                                Returned
                            </th>
                            <th scope="col" class="pr-3 py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="loan in loans.data" :key="loan.id">
                            <td class="pr-3 py-3 font-medium">
                                <a
                                    class="underline"
                                    :href="`/app/library/circulation/${loan.id}`"
                                    >{{ loan.titleName }}</a
                                >
                            </td>
                            <td class="pr-3 py-3 text-slate-600">{{ loan.copyCode }}</td>
                            <td class="pr-3 py-3 text-slate-600">{{ loan.studentName }}</td>
                            <td class="pr-3 py-3 text-slate-600">
                                {{ formatCalendarDate(loan.checkedOutAt) }}
                            </td>
                            <td
                                class="pr-3 py-3"
                                :class="
                                    loan.isOverdue ? 'font-medium text-red-600' : 'text-slate-600'
                                "
                            >
                                {{ formatCalendarDate(loan.dueAt)
                                }}<span v-if="loan.isOverdue"> (overdue)</span>
                            </td>
                            <td v-if="status !== 'active'" class="pr-3 py-3 text-slate-600">
                                {{ loan.returnedAt ? formatCalendarDate(loan.returnedAt) : '—' }}
                            </td>
                            <td class="pr-3 py-3 text-right">
                                <button
                                    v-if="canManage && status === 'active'"
                                    type="button"
                                    class="text-sm underline"
                                    @click="checkIn(loan.id)"
                                >
                                    Check in
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="loans.links" />
        </template>
    </main>
</template>
