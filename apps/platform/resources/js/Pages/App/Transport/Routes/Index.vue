<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface RouteRow {
    id: string;
    code: string;
    name: string;
    stopsCount: number;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    routes: {
        data: RouteRow[];
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
            '/app/transport/routes',
            { search: search.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Transport routes</h1>
                <p class="mt-1 text-sm text-slate-500">Routes serving your School.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/transport/routes/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add route
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-search">Search</label>
            <input
                id="filter-search"
                v-model="search"
                type="text"
                placeholder="Route name or code"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="routes.data.length === 0"
            class="mt-6"
            :title="search ? 'No routes match your search' : 'No routes yet'"
            :description="
                search
                    ? 'Try a different name or code.'
                    : 'Add the first route to begin setting up Transport.'
            "
        >
            <template v-if="canManage && !search" #action>
                <a
                    href="/app/transport/routes/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add route
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Code</th>
                        <th scope="col" class="py-2 font-medium">Name</th>
                        <th scope="col" class="py-2 font-medium">Stops</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="route in routes.data" :key="route.id">
                        <td class="py-3 font-medium">{{ route.code }}</td>
                        <td class="py-3">
                            <a class="underline" :href="`/app/transport/routes/${route.id}`">{{
                                route.name
                            }}</a>
                        </td>
                        <td class="py-3 text-slate-600">{{ route.stopsCount }}</td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="`/app/transport/routes/${route.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="routes.links" />
        </template>
    </main>
</template>
