<script setup lang="ts">
// SIS 11.1: the shared Student report page. Everything it shows -- the
// filters, their options, the columns and the rows -- comes from the
// report's closed definition and read model on the server, already cut to
// this viewer. Read only: no export, print or change from here.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatCalendarDate } from '../../../../datetime';

interface Option {
    value: string;
    label: string;
}

interface Filter {
    key: string;
    label: string;
    kind: 'search' | 'entity' | 'choice' | 'date';
    options: Option[];
}

interface Column {
    key: string;
    label: string;
    format: 'text' | 'date' | 'student' | 'placement' | 'lifecycle_reason';
}

interface StudentCell {
    name: string;
    studentNumber: string;
    url?: string;
}

interface PlacementCell {
    academicYear: string | null;
    campus: string | null;
    gradeLevel: string | null;
    section: string | null;
}

type Row = Record<string, unknown> & { key: string };

const props = defineProps<{
    report: { key: string; title: string; description: string; url: string };
    filters: Filter[];
    values: Record<string, string>;
    columns: Column[];
    sorts: Option[];
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    hasAny: boolean;
}>();

const form = ref<Record<string, string>>({ ...props.values });
const processing = ref(false);

// Browser Back/Forward restores the server's values: keep the form in step.
watch(
    () => props.values,
    (values) => {
        form.value = { ...values };
    },
);

// From the server's values (never the unsubmitted form), so it is never stale.
const hasFilters = computed(() =>
    Object.entries(props.values).some(([k, v]) => k !== 'sort' && v !== ''),
);

function apply(): void {
    if (processing.value) {
        return;
    }
    if (form.value.from && form.value.to && form.value.to < form.value.from) {
        form.value.to = '';
    }
    const query = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== ''));
    router.get(props.report.url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}

function reset(): void {
    form.value = Object.fromEntries(
        Object.keys(props.values).map((k) => [k, k === 'sort' ? (form.value.sort ?? '') : '']),
    );
    apply();
}

function student(row: Row, key: string): StudentCell | null {
    return (row[key] as StudentCell | null) ?? null;
}

function place(value: unknown): string {
    const p = value as PlacementCell | null;
    if (!p) {
        return '—';
    }
    return (
        [p.gradeLevel && p.section ? `${p.gradeLevel} · ${p.section}` : p.gradeLevel, p.campus]
            .filter(Boolean)
            .join(' · ') || '—'
    );
}

function year(value: unknown): string | null {
    return (value as PlacementCell | null)?.academicYear ?? null;
}

function text(value: unknown): string {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students/reports">← Student reports</a>
        <h1 class="mt-2 text-xl font-semibold">{{ report.title }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ report.description }}</p>

        <form
            class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            :aria-busy="processing"
            @submit.prevent="apply"
        >
            <div v-for="f in filters" :key="f.key">
                <label class="block text-sm text-slate-600" :for="`rf-${f.key}`">{{
                    f.label
                }}</label>
                <input
                    v-if="f.kind === 'search'"
                    :id="`rf-${f.key}`"
                    v-model="form[f.key]"
                    type="search"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-else-if="f.kind === 'date'"
                    :id="`rf-${f.key}`"
                    v-model="form[f.key]"
                    type="date"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                />
                <select
                    v-else
                    :id="`rf-${f.key}`"
                    v-model="form[f.key]"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="o in f.options" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </div>
            <div v-if="sorts.length > 1">
                <label class="block text-sm text-slate-600" for="rf-sort">Sort by</label>
                <select
                    id="rf-sort"
                    v-model="form.sort"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option v-for="(s, i) in sorts" :key="s.value" :value="i === 0 ? '' : s.value">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div class="flex items-end gap-3">
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-60"
                    :disabled="processing"
                >
                    Apply filters
                </button>
                <button v-if="hasFilters" type="button" class="text-sm underline" @click="reset">
                    Clear filters
                </button>
            </div>
        </form>

        <EmptyState
            v-if="rows.data.length === 0"
            class="mt-6"
            :title="hasFilters && hasAny ? 'Nothing matches these filters' : 'Nothing recorded yet'"
            :description="
                hasFilters && hasAny
                    ? 'Change or clear the filters to see more.'
                    : 'Rows appear here once the matching records exist.'
            "
        />

        <template v-else>
            <div class="relative mt-6 overflow-x-auto rounded border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        {{
                            report.title
                        }},
                        {{
                            rows.total
                        }}
                        in total
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th
                                v-for="c in columns"
                                :key="c.key"
                                scope="col"
                                class="px-3 py-2 font-medium"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="row in rows.data" :key="row.key">
                            <td v-for="c in columns" :key="c.key" class="px-3 py-2 align-top">
                                <template v-if="c.format === 'student'">
                                    <template v-if="student(row, c.key)">
                                        <a
                                            v-if="student(row, c.key)?.url"
                                            class="underline"
                                            :href="student(row, c.key)?.url"
                                            >{{ student(row, c.key)?.name }}</a
                                        >
                                        <span v-else>{{ student(row, c.key)?.name }}</span>
                                        <span class="block text-xs text-slate-500">{{
                                            student(row, c.key)?.studentNumber
                                        }}</span>
                                    </template>
                                    <span v-else>—</span>
                                </template>
                                <template v-else-if="c.format === 'placement'">
                                    {{ place(row[c.key]) }}
                                    <span
                                        v-if="year(row[c.key])"
                                        class="block text-xs text-slate-500"
                                        >{{ year(row[c.key]) }}</span
                                    >
                                </template>
                                <span v-else-if="c.format === 'date'" class="whitespace-nowrap">{{
                                    formatCalendarDate(row[c.key] as string | null) || '—'
                                }}</span>
                                <template v-else>{{ text(row[c.key]) }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                class="mt-4"
                :links="rows.links"
                :from="rows.from"
                :to="rows.to"
                :total="rows.total"
            />
        </template>
    </main>
</template>
