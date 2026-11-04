<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface AssignmentRow {
    id: string;
    studentName: string;
    studentNumber: string;
    bedCode: string;
    roomCode: string;
    hostelName: string;
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
    filters: {
        status: 'active' | 'ended';
    };
    canManage: boolean;
}

const props = defineProps<Props>();

function showStatus(status: 'active' | 'ended'): void {
    router.get('/app/hostel-residency', { status }, { preserveState: true, preserveScroll: true });
}

function endResidency(assignmentId: string): void {
    router.post(`/app/hostel-residency/${assignmentId}/end`);
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Hostel residency</h1>
                <p class="mt-1 text-sm text-ink-muted">Students currently assigned to a Bed.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/hostel-residency/create"
                class="btn btn-primary shrink-0"
            >
                Assign a Student
            </a>
        </div>

        <div class="mt-6 flex gap-4 border-b border-line text-sm">
            <button
                type="button"
                class="border-b-2 pb-2"
                :class="
                    props.filters.status === 'active'
                        ? 'border-accent-strong font-medium text-ink'
                        : 'border-transparent text-ink-muted'
                "
                @click="showStatus('active')"
            >
                Current residents
            </button>
            <button
                type="button"
                class="border-b-2 pb-2"
                :class="
                    props.filters.status === 'ended'
                        ? 'border-accent-strong font-medium text-ink'
                        : 'border-transparent text-ink-muted'
                "
                @click="showStatus('ended')"
            >
                History
            </button>
        </div>

        <EmptyState
            v-if="assignments.data.length === 0"
            class="mt-6"
            :title="
                props.filters.status === 'active' ? 'No current residents' : 'No past residencies'
            "
            description="Assign a Student to a Bed to see it here."
        >
            <template v-if="canManage && props.filters.status === 'active'" #action>
                <a href="/app/hostel-residency/create" class="btn btn-primary">
                    Assign a Student
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Hostel</th>
                        <th scope="col" class="py-2 font-medium">Room</th>
                        <th scope="col" class="py-2 font-medium">Bed</th>
                        <th scope="col" class="py-2 font-medium">Since</th>
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
                        <td class="py-3 text-ink-muted">{{ assignment.hostelName }}</td>
                        <td class="py-3 text-ink-muted">{{ assignment.roomCode }}</td>
                        <td class="py-3 text-ink-muted">{{ assignment.bedCode }}</td>
                        <td class="py-3 text-ink-muted">{{ assignment.startsOn }}</td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage && props.filters.status === 'active'"
                                type="button"
                                class="text-sm underline"
                                @click="endResidency(assignment.id)"
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
