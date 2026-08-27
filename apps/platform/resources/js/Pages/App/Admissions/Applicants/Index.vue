<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';

interface ApplicantRow {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    applicants: {
        data: ApplicantRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        name: string;
    };
    canManage: boolean;
}

const props = defineProps<Props>();

const name = ref(props.filters.name);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        '/app/admissions/applicants',
        { name: name.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(name, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

function fullName(applicant: ApplicantRow): string {
    return [applicant.firstName, applicant.middleName, applicant.lastName]
        .filter(Boolean)
        .join(' ');
}

const hasFilters = name.value.length > 0;
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/admissions">← Admissions</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Applicants</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Prospective students who have not yet enrolled.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/admissions/applicants/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Add applicant
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-name">Name</label>
                <input
                    id="filter-name"
                    v-model="name"
                    type="text"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
        </form>

        <EmptyState
            v-if="applicants.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No applicants match your search' : 'No applicants yet'"
            :description="
                hasFilters
                    ? 'Try a different name.'
                    : 'Add the first applicant to begin building your Admissions pipeline.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a
                    href="/app/admissions/applicants/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Add applicant
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Applicant</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="applicant in applicants.data" :key="applicant.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/admissions/applicants/${applicant.id}`"
                                >{{ fullName(applicant) }}</a
                            >
                        </td>
                        <td class="py-3 text-right">
                            <a
                                class="text-sm underline"
                                :href="`/app/admissions/applicants/${applicant.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="applicant in applicants.data"
                    :key="applicant.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a
                        class="font-medium underline"
                        :href="`/app/admissions/applicants/${applicant.id}`"
                        >{{ fullName(applicant) }}</a
                    >
                </li>
            </ul>

            <Pagination :links="applicants.links" />
        </template>
    </main>
</template>
