<script setup lang="ts">
// SIS 11.5: the cohort document checklist -- the derived state of each
// requirement per Student for one academic year. States only: no file,
// title or download is shown here (each Student's documents stay on their
// own page, behind the same MFA). Browser-only: no export or print. The
// server decides who may open it and which rows exist.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

interface Option {
    value: string;
    label: string;
    campus_id?: string;
    grade_level_id?: string;
}

interface Item {
    category: string;
    label: string;
    required: boolean;
    state: string;
    stateLabel: string;
}

interface Row {
    student: { id: string; displayName: string; studentNumber: string };
    placement: string;
    items: Item[];
    summary: {
        required: number;
        verified: number;
        missing: number;
        pending: number;
        rejected: number;
    };
}

const props = defineProps<{
    year: { id: string; name: string } | null;
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    options: Record<
        'academic_year_id' | 'campus_id' | 'grade_level_id' | 'section_id' | 'category' | 'status',
        Option[]
    >;
    sorts: Record<string, string>;
    filters: Record<string, string>;
    canManageRequirements: boolean;
}>();

const filters = ref({ ...props.filters });

const sections = computed(() =>
    props.options.section_id.filter(
        (s) =>
            (filters.value.campus_id === '' || s.campus_id === filters.value.campus_id) &&
            (filters.value.grade_level_id === '' ||
                s.grade_level_id === filters.value.grade_level_id),
    ),
);

function apply(): void {
    if (!sections.value.some((s) => s.value === filters.value.section_id)) {
        filters.value.section_id = '';
    }
    const query = Object.fromEntries(
        Object.entries(filters.value).filter(
            ([k, v]) => v !== '' && !(k === 'sort' && v === 'name'),
        ),
    );
    router.get('/app/students/document-compliance', query, { preserveScroll: true, replace: true });
}

const stateClass: Record<string, string> = {
    verified: 'text-ink',
    pending: 'text-ink',
    rejected: 'text-danger',
    missing: 'text-danger',
};

const labels: Record<string, string> = {
    campus_id: 'Campus',
    grade_level_id: 'Grade',
    category: 'Document type',
    status: 'State',
};
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/students/reports">← Student reports</a>
        <h1 class="mt-2 text-xl font-semibold">Document checklist</h1>
        <p class="mt-2 max-w-3xl text-sm text-ink-muted">
            Each Student's required and optional documents for an academic year. States only — open
            a Student's documents to see or verify a file. The checklist is guidance; it never
            blocks enrollment.
            <a
                v-if="canManageRequirements"
                class="underline"
                href="/app/students/document-requirements"
                >Document requirements</a
            >
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-sm text-ink-muted" for="f-year">Academic year</label>
                <select
                    id="f-year"
                    v-model="filters.academic_year_id"
                    class="input mt-1 w-auto"
                    @change="apply"
                >
                    <option v-for="o in options.academic_year_id" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </div>
            <div
                v-for="key in ['campus_id', 'grade_level_id', 'category', 'status'] as const"
                :key="key"
            >
                <label class="block text-sm text-ink-muted" :for="`f-${key}`">{{
                    labels[key]
                }}</label>
                <select
                    :id="`f-${key}`"
                    v-model="filters[key]"
                    class="input mt-1 w-auto"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="o in options[key]" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="f-section">Section</label>
                <select
                    id="f-section"
                    v-model="filters.section_id"
                    class="input mt-1 w-auto"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="o in sections" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="f-q">Student name or number</label>
                <input id="f-q" v-model="filters.q" maxlength="100" class="input mt-1 w-auto" />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="f-sort">Sort by</label>
                <select
                    id="f-sort"
                    v-model="filters.sort"
                    class="input mt-1 w-auto"
                    @change="apply"
                >
                    <option v-for="(label, key) in sorts" :key="key" :value="key">
                        {{ label }}
                    </option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>

        <EmptyState
            v-if="year === null"
            class="mt-6"
            title="No academic year"
            description="Create an academic year to see its document checklist."
        />
        <EmptyState
            v-else-if="rows.data.length === 0"
            class="mt-6"
            title="No Students match"
            description="No Student placed in this year matches these filters."
        />
        <template v-else>
            <p class="mt-6 text-sm text-ink-muted">
                {{ year.name }} · {{ rows.from }}–{{ rows.to }} of {{ rows.total }} Students
            </p>
            <div class="relative mt-2 overflow-x-auto rounded-xs border border-line">
                <table class="min-w-[48rem] w-full divide-y divide-line text-sm">
                    <caption class="sr-only">
                        Document checklist for
                        {{
                            year.name
                        }}
                    </caption>
                    <thead class="bg-graphite-100 text-left text-ink-muted">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Student</th>
                            <th scope="col" class="px-3 py-2 font-medium">Class</th>
                            <th scope="col" class="px-3 py-2 font-medium">Documents</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in rows.data" :key="r.student.id">
                            <td class="px-3 py-2">
                                <a
                                    class="font-medium underline"
                                    :href="`/app/students/${r.student.id}/documents`"
                                    >{{ r.student.displayName }}</a
                                >
                                <span class="block text-xs text-ink-muted">{{
                                    r.student.studentNumber
                                }}</span>
                            </td>
                            <td class="px-3 py-2">{{ r.placement || '—' }}</td>
                            <td class="px-3 py-2">
                                <span v-if="r.items.length === 0" class="text-ink-muted"
                                    >No requirements</span
                                >
                                <template v-else>
                                    <span class="text-xs text-ink-muted"
                                        >{{ r.summary.verified }} of
                                        {{ r.summary.required }} required verified</span
                                    >
                                    <ul class="mt-1 space-y-0.5">
                                        <li v-for="i in r.items" :key="i.category">
                                            {{ i.label
                                            }}<span
                                                v-if="!i.required"
                                                class="text-xs text-ink-muted"
                                            >
                                                (optional)</span
                                            >:
                                            <span
                                                :class="stateClass[i.state]"
                                                class="font-medium"
                                                >{{ i.stateLabel }}</span
                                            >
                                        </li>
                                    </ul>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination class="mt-4" :links="rows.links" />
        </template>
    </main>
</template>
