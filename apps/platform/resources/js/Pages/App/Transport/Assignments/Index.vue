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
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Student Transport assignments</h1>
                <p class="mt-1 text-sm text-ink-muted">Students currently assigned to a Route.</p>
            </div>
            <div v-if="canManage" class="flex shrink-0 gap-2">
                <a href="/app/transport/assignments/import" class="btn btn-secondary">
                    Import riders
                </a>
                <a href="/app/transport/assignments/create" class="btn btn-primary">
                    Assign a Student
                </a>
            </div>
        </div>

        <EmptyState
            v-if="assignments.data.length === 0"
            class="mt-6"
            title="No active assignments"
            description="Assign a Student to a Route to see it here."
        >
            <template v-if="canManage" #action>
                <a href="/app/transport/assignments/create" class="btn btn-primary">
                    Assign a Student
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Route</th>
                        <th scope="col" class="py-2 font-medium">Pickup</th>
                        <th scope="col" class="py-2 font-medium">Drop-off</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="assignment in assignments.data" :key="assignment.id">
                        <td class="py-3 font-medium">
                            {{ assignment.studentName }} ({{ assignment.studentNumber }})
                        </td>
                        <td class="py-3 text-ink-muted">{{ assignment.routeName }}</td>
                        <td class="py-3 text-ink-muted">{{ assignment.pickupStopName ?? '—' }}</td>
                        <td class="py-3 text-ink-muted">{{ assignment.dropoffStopName ?? '—' }}</td>
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
