<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
    status: 'active' | 'returned';
    canManage: boolean;
}

defineProps<Props>();

const views = [
    { status: 'active', label: 'Active loans', href: '/app/library/circulation' },
    {
        status: 'returned',
        label: 'Returned loans',
        href: '/app/library/circulation?status=returned',
    },
] as const;

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
                            : 'Returned loans, most recently returned first. Open a loan to see its fine, if any.'
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

        <EmptyState
            v-if="loans.data.length === 0"
            class="mt-6"
            :title="status === 'active' ? 'No active loans' : 'No returned loans'"
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
                            status === 'active' ? 'Active loans' : 'Returned loans'
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="pr-3 py-2 font-medium">Title</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Copy</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Student</th>
                            <th scope="col" class="pr-3 py-2 font-medium">Due</th>
                            <th
                                v-if="status === 'returned'"
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
                            <td
                                class="pr-3 py-3"
                                :class="
                                    loan.isOverdue ? 'font-medium text-red-600' : 'text-slate-600'
                                "
                            >
                                {{ loan.dueAt }}<span v-if="loan.isOverdue"> (overdue)</span>
                            </td>
                            <td v-if="status === 'returned'" class="pr-3 py-3 text-slate-600">
                                {{ loan.returnedAt }}
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
