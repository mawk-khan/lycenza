<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface GuardianRow {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
    status: 'active' | 'inactive';
    linkedStudentCount: number;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    guardians: {
        data: GuardianRow[];
        links: PageLink[];
        total: number;
    };
    filters: { name: string; status: string };
    canManage: boolean;
}

const props = defineProps<Props>();

const name = ref(props.filters.name);
const status = ref(props.filters.status);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/guardians',
        { name: name.value || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(name, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

function fullName(g: GuardianRow): string {
    return [g.firstName, g.middleName, g.lastName].filter(Boolean).join(' ');
}

// SIS 2.2: reactive, from the filters the list was actually loaded with
// (filtering keeps this component mounted -- preserveState).
const hasFilters = computed(() => !!props.filters.name || !!props.filters.status);
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Home</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Guardians</h1>
                <p class="mt-1 text-sm text-ink-muted">Your School's guardian directory.</p>
            </div>
            <a v-if="canManage" href="/app/guardians/create" class="btn btn-primary shrink-0">
                Add guardian
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-name">Name</label>
                <input id="filter-name" v-model="name" type="text" class="input mt-1 w-56" />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-status">Status</label>
                <select
                    id="filter-status"
                    v-model="status"
                    class="input mt-1 w-36"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="guardians.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No guardians match your search' : 'No guardians yet'"
            :description="
                hasFilters
                    ? 'Try a different name or status.'
                    : 'Guardians are usually added from a Student\'s page, but you can also add one directly.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a href="/app/guardians/create" class="btn btn-primary"> Add guardian </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Guardian</th>
                        <th scope="col" class="py-2 font-medium">Linked students</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="guardian in guardians.data" :key="guardian.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/guardians/${guardian.id}`"
                                >{{ fullName(guardian) }}</a
                            >
                        </td>
                        <td class="py-3 text-ink-muted">{{ guardian.linkedStudentCount }}</td>
                        <td class="py-3"><StatusBadge :status="guardian.status" /></td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="`/app/guardians/${guardian.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="guardian in guardians.data"
                    :key="guardian.id"
                    class="rounded-xs border border-line p-4"
                >
                    <a class="font-medium underline" :href="`/app/guardians/${guardian.id}`">{{
                        fullName(guardian)
                    }}</a>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ guardian.linkedStudentCount }} linked student{{
                            guardian.linkedStudentCount === 1 ? '' : 's'
                        }}
                    </p>
                    <div class="mt-2"><StatusBadge :status="guardian.status" /></div>
                </li>
            </ul>

            <Pagination :links="guardians.links" />
        </template>
    </main>
</template>
