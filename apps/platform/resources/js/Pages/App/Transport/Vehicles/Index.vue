<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface VehicleRow {
    id: string;
    code: string;
    registrationNumber: string;
    capacity: number | null;
    status: 'active' | 'inactive';
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    vehicles: {
        data: VehicleRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        search: string;
    };
    canManage: boolean;
}

const props = defineProps<Props>();

const search = ref(props.filters.search);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            '/app/transport/vehicles',
            { search: search.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

function toggleStatus(vehicle: VehicleRow): void {
    router.patch(`/app/transport/vehicles/${vehicle.id}`, {
        status: vehicle.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Transport vehicles</h1>
                <p class="mt-1 text-sm text-slate-500">The fleet available to your School.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/transport/vehicles/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add vehicle
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-search">Search</label>
            <input
                id="filter-search"
                v-model="search"
                type="text"
                placeholder="Code or registration number"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="vehicles.data.length === 0"
            class="mt-6"
            :title="search ? 'No vehicles match your search' : 'No vehicles yet'"
            :description="
                search
                    ? 'Try a different code or registration number.'
                    : 'Add the first vehicle to begin setting up Transport.'
            "
        >
            <template v-if="canManage && !search" #action>
                <a
                    href="/app/transport/vehicles/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add vehicle
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Code</th>
                        <th scope="col" class="py-2 font-medium">Registration</th>
                        <th scope="col" class="py-2 font-medium">Capacity</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="vehicle in vehicles.data" :key="vehicle.id">
                        <td class="py-3 font-medium">{{ vehicle.code }}</td>
                        <td class="py-3 text-slate-600">{{ vehicle.registrationNumber }}</td>
                        <td class="py-3 text-slate-600">{{ vehicle.capacity ?? '—' }}</td>
                        <td class="py-3"><StatusBadge :status="vehicle.status" /></td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-sm underline"
                                @click="toggleStatus(vehicle)"
                            >
                                {{ vehicle.status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="vehicles.links" />
        </template>
    </main>
</template>
