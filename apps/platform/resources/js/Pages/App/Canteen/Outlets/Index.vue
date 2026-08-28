<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface OutletRow {
    id: string;
    code: string;
    name: string;
    status: 'active' | 'inactive';
    campusName: string | null;
    inventoryLocationCode: string | null;
    inventoryLocationName: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    outlets: {
        data: OutletRow[];
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
            '/app/canteen-outlets',
            { search: search.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

function toggleStatus(outlet: OutletRow): void {
    router.patch(`/app/canteen-outlets/${outlet.id}`, {
        status: outlet.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Canteen outlets</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Counters that issue Canteen menu Items, each backed by an Inventory Location.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/canteen-outlets/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add Outlet
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-search">Search</label>
            <input
                id="filter-search"
                v-model="search"
                type="text"
                placeholder="Code or name"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="outlets.data.length === 0"
            class="mt-6"
            :title="search ? 'No Outlets match your search' : 'No Outlets yet'"
            :description="
                search
                    ? 'Try a different code or name.'
                    : 'Add the first Outlet to begin taking Canteen orders.'
            "
        >
            <template v-if="canManage && !search" #action>
                <a
                    href="/app/canteen-outlets/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add Outlet
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Code</th>
                        <th scope="col" class="py-2 font-medium">Name</th>
                        <th scope="col" class="py-2 font-medium">Campus</th>
                        <th scope="col" class="py-2 font-medium">Inventory Location</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="outlet in outlets.data" :key="outlet.id">
                        <td class="py-3 font-medium">{{ outlet.code }}</td>
                        <td class="py-3">{{ outlet.name }}</td>
                        <td class="py-3">{{ outlet.campusName ?? 'School-wide' }}</td>
                        <td class="py-3">
                            <template v-if="outlet.inventoryLocationCode">
                                {{ outlet.inventoryLocationCode }} —
                                {{ outlet.inventoryLocationName }}
                            </template>
                        </td>
                        <td class="py-3"><StatusBadge :status="outlet.status" /></td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-sm underline"
                                @click="toggleStatus(outlet)"
                            >
                                {{ outlet.status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="outlets.links" />
        </template>
    </main>
</template>
