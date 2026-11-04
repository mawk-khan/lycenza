<script setup lang="ts">
// ORG.3 (ADR 0076 §9.2): the Platform School Registry -- non-business
// tenant metadata only (identity, lifecycle, Campus counts, active
// academic-year name, domain state, Group membership, derived readiness).
// Never students, staff, money, marks or any School audit content.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import { domainLabels, readinessStyles, statusLabel } from '../../../../schoolRegistry';

interface RegistryRow {
    id: string;
    name: string;
    legalName: string | null;
    slug: string;
    code: string | null;
    status: string;
    closed: boolean;
    createdAt: string | null;
    campuses: { active: number; inactive: number };
    academicYear: string | null;
    domain: string;
    groups: { id: string; name: string }[];
    readiness: string;
    warningCount: number;
}

interface Props {
    schools: RegistryRow[];
    nextCursor: string | null;
    perPage: number;
    filters: {
        q: string;
        status: string | null;
        group: string | null;
        domain: string | null;
        perPage: number;
        paged: boolean;
    };
    options: { statuses: string[]; domains: string[]; groups: { id: string; name: string }[] };
    canCreate: boolean;
}

const props = defineProps<Props>();

const q = ref(props.filters.q);
const status = ref(props.filters.status ?? '');
const group = ref(props.filters.group ?? '');
const domain = ref(props.filters.domain ?? '');

function query(cursor?: string): Record<string, string | undefined> {
    return {
        q: q.value.trim() || undefined,
        status: status.value || undefined,
        group: group.value || undefined,
        domain: domain.value || undefined,
        cursor,
    };
}

function applyFilters(): void {
    router.get('/app/platform/schools', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

const hasFilters = computed(
    () =>
        props.filters.q !== '' ||
        props.filters.status !== null ||
        props.filters.group !== null ||
        props.filters.domain !== null,
);

function resetFilters(): void {
    q.value = '';
    status.value = '';
    group.value = '';
    domain.value = '';
    clearTimeout(debounceTimer);
    applyFilters();
}

function nextPage(): void {
    if (props.nextCursor) {
        router.get('/app/platform/schools', query(props.nextCursor), { preserveScroll: false });
    }
}

function campusLabel(row: RegistryRow): string {
    const inactive = row.campuses.inactive ? ` (+${row.campuses.inactive} inactive)` : '';
    return `${row.campuses.active} active${inactive}`;
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-4 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">School Registry</h1>
                <p class="mt-1 max-w-2xl text-sm text-ink-muted">
                    Every School on the platform, with its lifecycle and setup state. Platform
                    access shows School metadata only and never grants access to any School.
                </p>
            </div>
            <a v-if="canCreate" href="/app/platform/schools/create" class="btn btn-primary shrink-0"
                >Create a School</a
            >
        </div>

        <form
            class="mt-6 flex flex-wrap items-end gap-3"
            role="search"
            @submit.prevent="applyFilters"
        >
            <div class="w-full sm:w-auto">
                <label class="block text-sm text-ink-muted" for="registry-search">Search</label>
                <input
                    id="registry-search"
                    v-model="q"
                    type="search"
                    maxlength="100"
                    placeholder="Name, legal name, slug or code"
                    class="input mt-1 sm:w-72"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="registry-status">Status</label>
                <select
                    id="registry-status"
                    v-model="status"
                    class="input mt-1 w-40"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="s in options.statuses" :key="s" :value="s">
                        {{ statusLabel(s, s === 'closed') }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="registry-domain"
                    >Custom domain</label
                >
                <select
                    id="registry-domain"
                    v-model="domain"
                    class="input mt-1 w-44"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="d in options.domains" :key="d" :value="d">
                        {{ domainLabels[d] ?? d }}
                    </option>
                </select>
            </div>
            <div v-if="options.groups.length">
                <label class="block text-sm text-ink-muted" for="registry-group"
                    >School Group</label
                >
                <select
                    id="registry-group"
                    v-model="group"
                    class="input mt-1 w-48"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="g in options.groups" :key="g.id" :value="g.id">
                        {{ g.name }}
                    </option>
                </select>
            </div>
            <button
                v-if="hasFilters"
                type="button"
                class="pb-2 text-sm underline"
                @click="resetFilters"
            >
                Clear filters
            </button>
        </form>

        <EmptyState
            v-if="schools.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No Schools match' : 'No Schools yet'"
            :description="
                hasFilters
                    ? 'Try a different search or filter.'
                    : 'A School created here starts as provisioning.'
            "
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table" data-testid="school-list">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">School</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">Campuses</th>
                        <th scope="col" class="py-2 font-medium">Academic year</th>
                        <th scope="col" class="py-2 font-medium">Custom domain</th>
                        <th scope="col" class="py-2 font-medium">Readiness</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in schools" :key="s.id" class="border-b border-line align-top">
                        <td class="py-3 pr-4">
                            <a
                                class="font-medium underline"
                                :href="`/app/platform/schools/${s.id}`"
                                >{{ s.name }}</a
                            >
                            <p class="text-xs text-ink-muted">
                                {{ s.slug }}<template v-if="s.code"> · {{ s.code }}</template>
                                <template v-if="s.groups.length">
                                    · {{ s.groups.map((g) => g.name).join(', ') }}</template
                                >
                            </p>
                        </td>
                        <td class="py-3 pr-4">{{ statusLabel(s.status, s.closed) }}</td>
                        <td class="py-3 pr-4">{{ campusLabel(s) }}</td>
                        <td class="py-3 pr-4">
                            <span v-if="s.academicYear">{{ s.academicYear }}</span>
                            <span v-else class="text-ink-muted">None active</span>
                        </td>
                        <td class="py-3 pr-4">{{ domainLabels[s.domain] ?? s.domain }}</td>
                        <td class="py-3">
                            <span
                                class="rounded-xs px-2 py-0.5 text-xs font-medium"
                                :class="readinessStyles[s.readiness]?.badge"
                                >{{ readinessStyles[s.readiness]?.label ?? s.readiness }}</span
                            >
                            <span
                                v-if="s.warningCount"
                                class="ml-2 text-xs text-ink"
                                :aria-label="`${s.warningCount} warnings`"
                                >⚠ {{ s.warningCount }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li v-for="s in schools" :key="s.id" class="rounded-xs border border-line p-3">
                    <a class="font-medium underline" :href="`/app/platform/schools/${s.id}`">{{
                        s.name
                    }}</a>
                    <p class="text-xs text-ink-muted">
                        {{ s.slug }} · {{ statusLabel(s.status, s.closed) }}
                    </p>
                    <p class="mt-1 text-sm">
                        <span
                            class="rounded-xs px-2 py-0.5 text-xs font-medium"
                            :class="readinessStyles[s.readiness]?.badge"
                            >{{ readinessStyles[s.readiness]?.label ?? s.readiness }}</span
                        >
                        <span v-if="s.warningCount" class="ml-2 text-xs text-ink"
                            >⚠ {{ s.warningCount }}</span
                        >
                    </p>
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ campusLabel(s) }} campuses · {{ s.academicYear ?? 'No active year' }}
                    </p>
                </li>
            </ul>

            <nav aria-label="Pagination" class="mt-4 flex items-center gap-4 text-sm">
                <button v-if="filters.paged" type="button" class="underline" @click="applyFilters">
                    First page
                </button>
                <button v-if="nextCursor" type="button" class="underline" @click="nextPage">
                    Next page
                </button>
            </nav>
        </template>
    </main>
</template>
