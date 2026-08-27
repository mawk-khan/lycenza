<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface TitleRow {
    id: string;
    title: string;
    author: string | null;
    copiesCount: number;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    titles: {
        data: TitleRow[];
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
            '/app/library/titles',
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
                <h1 class="text-xl font-semibold">Library catalogue</h1>
                <p class="mt-1 text-sm text-slate-500">Titles in your School's library.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/library/titles/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add title
            </a>
        </div>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-search">Search</label>
            <input
                id="filter-search"
                v-model="search"
                type="text"
                placeholder="Title or author"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </div>

        <EmptyState
            v-if="titles.data.length === 0"
            class="mt-6"
            :title="search ? 'No titles match your search' : 'No titles yet'"
            :description="
                search
                    ? 'Try a different title or author.'
                    : 'Add the first title to begin building your School\'s library catalogue.'
            "
        >
            <template v-if="canManage && !search" #action>
                <a
                    href="/app/library/titles/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add title
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Title</th>
                        <th scope="col" class="py-2 font-medium">Author</th>
                        <th scope="col" class="py-2 font-medium">Copies</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="title in titles.data" :key="title.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/library/titles/${title.id}`"
                                >{{ title.title }}</a
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ title.author ?? '—' }}</td>
                        <td class="py-3 text-slate-600">{{ title.copiesCount }}</td>
                        <td class="py-3 text-right">
                            <a class="text-sm underline" :href="`/app/library/titles/${title.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="titles.links" />
        </template>
    </main>
</template>
