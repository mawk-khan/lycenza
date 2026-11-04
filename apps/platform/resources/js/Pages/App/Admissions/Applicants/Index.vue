<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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

// SIS 3.2: reactive, from the filter the list was actually loaded with
// (searching keeps this component mounted -- preserveState).
const hasFilters = computed(() => !!props.filters.name);
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/admissions">← Admissions</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Applicants</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    Prospective students who have not yet enrolled.
                </p>
            </div>
            <a
                v-if="canManage"
                href="/app/admissions/applicants/create"
                class="btn btn-primary shrink-0"
            >
                Add applicant
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-name">Name</label>
                <input id="filter-name" v-model="name" type="text" class="input mt-1 w-56" />
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
                <a href="/app/admissions/applicants/create" class="btn btn-primary">
                    Add applicant
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Applicant</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
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
                    class="rounded-xs border border-line p-4"
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
