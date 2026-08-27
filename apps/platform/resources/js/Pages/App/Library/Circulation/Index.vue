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
    canManage: boolean;
}

defineProps<Props>();

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
                <p class="mt-1 text-sm text-slate-500">Currently active loans.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/library/circulation/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Check out an item
            </a>
        </div>

        <EmptyState
            v-if="loans.data.length === 0"
            class="mt-6"
            title="No active loans"
            description="Check out a Library item to a Student to see it here."
        >
            <template v-if="canManage" #action>
                <a
                    href="/app/library/circulation/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Check out an item
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Title</th>
                        <th scope="col" class="py-2 font-medium">Copy</th>
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Due</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="loan in loans.data" :key="loan.id">
                        <td class="py-3 font-medium">{{ loan.titleName }}</td>
                        <td class="py-3 text-slate-600">{{ loan.copyCode }}</td>
                        <td class="py-3 text-slate-600">{{ loan.studentName }}</td>
                        <td
                            class="py-3"
                            :class="loan.isOverdue ? 'font-medium text-red-600' : 'text-slate-600'"
                        >
                            {{ loan.dueAt }}<span v-if="loan.isOverdue"> (overdue)</span>
                        </td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
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

            <Pagination :links="loans.links" />
        </template>
    </main>
</template>
