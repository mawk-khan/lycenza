<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface AssignmentRow {
    id: string;
    studentName: string;
    studentNumber: string;
    routeName: string;
    pickupStopName: string | null;
    dropoffStopName: string | null;
    startsOn: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    assignments: {
        data: AssignmentRow[];
        links: PageLink[];
        total: number;
    };
    canManage: boolean;
}

defineProps<Props>();

function endAssignment(assignmentId: string): void {
    router.post(`/app/transport/assignments/${assignmentId}/end`);
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Student Transport assignments</h1>
                <p class="mt-1 text-sm text-slate-500">Students currently assigned to a Route.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/transport/assignments/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Assign a Student
            </a>
        </div>

        <EmptyState
            v-if="assignments.data.length === 0"
            class="mt-6"
            title="No active assignments"
            description="Assign a Student to a Route to see it here."
        >
            <template v-if="canManage" #action>
                <a
                    href="/app/transport/assignments/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Assign a Student
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Route</th>
                        <th scope="col" class="py-2 font-medium">Pickup</th>
                        <th scope="col" class="py-2 font-medium">Drop-off</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="assignment in assignments.data" :key="assignment.id">
                        <td class="py-3 font-medium">
                            {{ assignment.studentName }} ({{ assignment.studentNumber }})
                        </td>
                        <td class="py-3 text-slate-600">{{ assignment.routeName }}</td>
                        <td class="py-3 text-slate-600">{{ assignment.pickupStopName ?? '—' }}</td>
                        <td class="py-3 text-slate-600">{{ assignment.dropoffStopName ?? '—' }}</td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-sm underline"
                                @click="endAssignment(assignment.id)"
                            >
                                End
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="assignments.links" />
        </template>
    </main>
</template>
