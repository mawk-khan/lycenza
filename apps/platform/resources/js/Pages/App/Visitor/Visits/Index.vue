<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface VisitRow {
    id: string;
    visitorName: string;
    campusName: string;
    hostName: string | null;
    purpose: string;
    checkedInAt: string;
    checkedOutAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    visits: {
        data: VisitRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        status: 'checked_in' | 'checked_out';
    };
    canManage: boolean;
}

const props = defineProps<Props>();

function showStatus(status: 'checked_in' | 'checked_out'): void {
    router.get('/app/visitor/visits', { status }, { preserveState: true, preserveScroll: true });
}

function endVisit(visitId: string): void {
    router.post(`/app/visitor/visits/${visitId}/end`);
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Visitor visits</h1>
                <p class="mt-1 text-sm text-slate-500">Front-desk check-in/check-out.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/visitor/visits/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Check in a Visitor
            </a>
        </div>

        <div class="mt-6 flex gap-4 border-b border-slate-200 text-sm">
            <button
                type="button"
                class="border-b-2 pb-2"
                :class="
                    props.filters.status === 'checked_in'
                        ? 'border-slate-900 font-medium text-slate-900'
                        : 'border-transparent text-slate-500'
                "
                @click="showStatus('checked_in')"
            >
                Currently checked in
            </button>
            <button
                type="button"
                class="border-b-2 pb-2"
                :class="
                    props.filters.status === 'checked_out'
                        ? 'border-slate-900 font-medium text-slate-900'
                        : 'border-transparent text-slate-500'
                "
                @click="showStatus('checked_out')"
            >
                History
            </button>
        </div>

        <EmptyState
            v-if="visits.data.length === 0"
            class="mt-6"
            :title="
                props.filters.status === 'checked_in'
                    ? 'No Visitors currently checked in'
                    : 'No past visits'
            "
            description="Check in a Visitor to see it here."
        >
            <template v-if="canManage && props.filters.status === 'checked_in'" #action>
                <a
                    href="/app/visitor/visits/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Check in a Visitor
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Visitor</th>
                        <th scope="col" class="py-2 font-medium">Campus</th>
                        <th scope="col" class="py-2 font-medium">Host</th>
                        <th scope="col" class="py-2 font-medium">Checked in</th>
                        <th scope="col" class="py-2 font-medium">Checked out</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="visit in visits.data" :key="visit.id">
                        <td class="py-3 font-medium">{{ visit.visitorName }}</td>
                        <td class="py-3 text-slate-600">{{ visit.campusName }}</td>
                        <td class="py-3 text-slate-600">{{ visit.hostName ?? '—' }}</td>
                        <td class="py-3 text-slate-600">
                            {{ new Date(visit.checkedInAt).toLocaleString() }}
                        </td>
                        <td class="py-3 text-slate-600">
                            {{
                                visit.checkedOutAt
                                    ? new Date(visit.checkedOutAt).toLocaleString()
                                    : '—'
                            }}
                        </td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage && props.filters.status === 'checked_in'"
                                type="button"
                                class="text-sm underline"
                                @click="endVisit(visit.id)"
                            >
                                Check out
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="visits.links" />
        </template>
    </main>
</template>
