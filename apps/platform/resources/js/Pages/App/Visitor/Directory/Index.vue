<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface VisitorRow {
    id: string;
    fullName: string;
    phone: string | null;
    status: 'active' | 'inactive';
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    visitors: {
        data: VisitorRow[];
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
            '/app/visitor/directory',
            { search: search.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

function toggleStatus(visitor: VisitorRow): void {
    router.patch(`/app/visitor/directory/${visitor.id}`, {
        status: visitor.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Visitor directory</h1>
                <p class="mt-1 text-sm text-slate-500">People known to your School's front desk.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/visitor/directory/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add Visitor
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-search">Search</label>
            <input
                id="filter-search"
                v-model="search"
                type="text"
                placeholder="Name or phone"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="visitors.data.length === 0"
            class="mt-6"
            :title="search ? 'No Visitors match your search' : 'No Visitors yet'"
            :description="
                search
                    ? 'Try a different name or phone number.'
                    : 'Add the first Visitor to begin using check-in.'
            "
        >
            <template v-if="canManage && !search" #action>
                <a
                    href="/app/visitor/directory/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add Visitor
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Name</th>
                        <th scope="col" class="py-2 font-medium">Phone</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="visitor in visitors.data" :key="visitor.id">
                        <td class="py-3 font-medium">{{ visitor.fullName }}</td>
                        <td class="py-3 text-slate-600">{{ visitor.phone ?? '—' }}</td>
                        <td class="py-3"><StatusBadge :status="visitor.status" /></td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-sm underline"
                                @click="toggleStatus(visitor)"
                            >
                                {{ visitor.status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="visitors.links" />
        </template>
    </main>
</template>
