<script setup lang="ts">
import Pagination from '../../../../Components/Pagination.vue';

interface RouteRow {
    id: string;
    code: string;
    name: string;
    currentAssignment: { id: string; vehicleCode: string; driverName: string } | null;
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
    canManage: boolean;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2">
            <h1 class="text-xl font-semibold">Route operations</h1>
            <p class="mt-1 text-sm text-slate-500">
                Current Vehicle/Driver assignment for each active Route.
            </p>
        </div>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Route</th>
                    <th scope="col" class="py-2 font-medium">Vehicle</th>
                    <th scope="col" class="py-2 font-medium">Driver</th>
                    <th scope="col" class="py-2 font-medium">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="route in routes.data" :key="route.id">
                    <td class="py-3 font-medium">{{ route.name }}</td>
                    <td class="py-3 text-slate-600">
                        {{ route.currentAssignment?.vehicleCode ?? 'Unassigned' }}
                    </td>
                    <td class="py-3 text-slate-600">
                        {{ route.currentAssignment?.driverName ?? '—' }}
                    </td>
                    <td class="py-3 text-right">
                        <a class="text-sm underline" :href="`/app/transport/operations/${route.id}`"
                            >Manage</a
                        >
                    </td>
                </tr>
            </tbody>
        </table>

        <Pagination :links="routes.links" />
    </main>
</template>
