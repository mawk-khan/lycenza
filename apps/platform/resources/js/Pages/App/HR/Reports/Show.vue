<script setup lang="ts">
// HR.14: the shared HR report page. Everything it shows -- the filters, their
// options, the columns a viewer may choose, and the rows -- comes from the
// report's closed definition and read model on the server, already cut to
// this viewer. Read only and browser-only: no export, print or change here.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatCalendarDate, formatDateTime } from '../../../../datetime';

interface Option {
    value: string;
    label: string;
}

interface Filter {
    key: string;
    label: string;
    kind: 'search' | 'entity' | 'choice' | 'date' | 'month';
    options: Option[];
}

interface Column {
    key: string;
    label: string;
    format: 'text' | 'date' | 'datetime' | 'number' | 'employee';
    default: boolean;
}

interface EmployeeCell {
    name: string;
    number: string;
    url: string;
}

type Row = Record<string, unknown> & { key: string };

const props = defineProps<{
    report: { key: string; title: string; description: string; url: string; basis: string };
    filters: Filter[];
    values: Record<string, string>;
    defaults: Record<string, string>;
    columns: Column[];
    selectable: Column[];
    chosen: string[];
    sorts: Option[];
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    hasAny: boolean;
    provenance: {
        source: string;
        basis: string;
        timezone: string;
        generatedAt: string;
        freshness: string;
    };
    errors?: Record<string, string>;
}>();

const form = ref<Record<string, string>>({ ...props.values });
const picked = ref<string[]>([...props.chosen]);
const processing = ref(false);
const columnsOpen = ref(false);

watch(
    () => props.values,
    (values) => {
        form.value = { ...values };
    },
);
watch(
    () => props.chosen,
    (chosen) => {
        picked.value = [...chosen];
    },
);

// A select filter with no choices is not offered at all.
const shownFilters = computed(() =>
    props.filters.filter(
        (f) =>
            f.kind === 'search' || f.kind === 'date' || f.kind === 'month' || f.options.length > 0,
    ),
);

// Different from the defaults the server applied (the date, Leave Year, ...).
const hasFilters = computed(() =>
    Object.entries(props.values).some(
        ([k, v]) => k !== 'sort' && v !== '' && v !== (props.defaults[k] ?? ''),
    ),
);

function query(): Record<string, string | string[]> {
    const q: Record<string, string | string[]> = Object.fromEntries(
        Object.entries(form.value).filter(([, v]) => v !== ''),
    );
    if (props.selectable.length > 0 && picked.value.length > 0) {
        q.columns = picked.value;
    }
    return q;
}

function apply(): void {
    if (processing.value) {
        return;
    }
    router.get(props.report.url, query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}

function reset(): void {
    form.value = Object.fromEntries(Object.keys(props.values).map((k) => [k, '']));
    apply();
}

function employee(row: Row, key: string): EmployeeCell | null {
    return (row[key] as EmployeeCell | null) ?? null;
}

function text(value: unknown): string {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/hr/reports">← HR reports</a>
        <h1 class="mt-2 text-xl font-semibold">{{ report.title }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ report.description }}</p>
        <p class="mt-1 text-xs text-ink-muted">
            {{ provenance.basis }} · Source: {{ provenance.source }} ·
            {{ provenance.freshness }} Generated
            {{ formatDateTime(provenance.generatedAt, provenance.timezone) }} ({{
                provenance.timezone
            }}).
        </p>

        <form
            class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            :aria-busy="processing"
            @submit.prevent="apply"
        >
            <div v-for="f in shownFilters" :key="f.key">
                <label class="block text-sm text-ink-muted" :for="`hf-${f.key}`">{{
                    f.label
                }}</label>
                <input
                    v-if="f.kind === 'search'"
                    :id="`hf-${f.key}`"
                    v-model="form[f.key]"
                    :aria-invalid="!!errors?.[f.key]"
                    :aria-describedby="errors?.[f.key] ? `hf-${f.key}-error` : undefined"
                    type="search"
                    maxlength="100"
                    class="input mt-1"
                />
                <input
                    v-else-if="f.kind === 'date' || f.kind === 'month'"
                    :id="`hf-${f.key}`"
                    v-model="form[f.key]"
                    :type="f.kind === 'month' ? 'month' : 'date'"
                    :min="(f.key === 'to' && form.from) || undefined"
                    :aria-invalid="!!errors?.[f.key]"
                    :aria-describedby="errors?.[f.key] ? `hf-${f.key}-error` : undefined"
                    class="input mt-1"
                    @change="apply"
                />
                <select
                    v-else
                    :id="`hf-${f.key}`"
                    v-model="form[f.key]"
                    :aria-invalid="!!errors?.[f.key]"
                    :aria-describedby="errors?.[f.key] ? `hf-${f.key}-error` : undefined"
                    class="input mt-1"
                    @change="apply"
                >
                    <option v-if="!defaults[f.key]" value="">All</option>
                    <option v-for="o in f.options" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
                <p
                    v-if="errors?.[f.key]"
                    :id="`hf-${f.key}-error`"
                    class="mt-1 text-sm text-danger"
                >
                    {{ errors[f.key] }}
                </p>
            </div>
            <div v-if="sorts.length > 1">
                <label class="block text-sm text-ink-muted" for="hf-sort">Sort by</label>
                <select id="hf-sort" v-model="form.sort" class="input mt-1" @change="apply">
                    <option v-for="(s, i) in sorts" :key="s.value" :value="i === 0 ? '' : s.value">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div class="flex items-end gap-3">
                <button type="submit" class="btn btn-primary" :disabled="processing">
                    Apply filters
                </button>
                <button v-if="hasFilters" type="button" class="text-sm underline" @click="reset">
                    Clear filters
                </button>
            </div>
        </form>

        <section v-if="selectable.length > 0" class="mt-4">
            <button
                type="button"
                class="btn btn-secondary"
                :aria-expanded="columnsOpen"
                aria-controls="hr-report-columns"
                @click="columnsOpen = !columnsOpen"
            >
                Choose columns
            </button>
            <fieldset
                v-show="columnsOpen"
                id="hr-report-columns"
                class="mt-3 rounded-xs border border-line p-3"
            >
                <legend class="px-1 text-sm font-medium">Columns to show</legend>
                <p v-if="errors?.columns" class="text-sm text-danger">{{ errors.columns }}</p>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="c in selectable"
                        :key="c.key"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input v-model="picked" type="checkbox" :value="c.key" />
                        {{ c.label }}
                    </label>
                </div>
                <button
                    type="button"
                    class="btn btn-primary mt-3"
                    :disabled="processing || picked.length === 0"
                    @click="apply"
                >
                    Show these columns
                </button>
            </fieldset>
        </section>

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
            <div class="relative mt-6 overflow-x-auto rounded-xs border border-line">
                <table class="data-table min-w-full divide-y divide-line text-sm">
                    <caption class="sr-only">
                        {{
                            report.title
                        }},
                        {{
                            rows.total
                        }}
                        rows in total
                    </caption>
                    <thead class="bg-graphite-100 text-left text-ink-muted">
                        <tr>
                            <th
                                v-for="c in columns"
                                :key="c.key"
                                scope="col"
                                class="px-3 py-2 font-medium"
                                :class="{ 'text-right': c.format === 'number' }"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows.data" :key="row.key">
                            <td
                                v-for="c in columns"
                                :key="c.key"
                                class="px-3 py-2 align-top"
                                :class="{ 'text-right tabular-nums': c.format === 'number' }"
                            >
                                <template v-if="c.format === 'employee'">
                                    <template v-if="employee(row, c.key)">
                                        <a class="underline" :href="employee(row, c.key)?.url">{{
                                            employee(row, c.key)?.name
                                        }}</a>
                                        <span class="block text-xs text-ink-muted">{{
                                            employee(row, c.key)?.number
                                        }}</span>
                                    </template>
                                    <span v-else>—</span>
                                </template>
                                <span v-else-if="c.format === 'date'" class="whitespace-nowrap">{{
                                    formatCalendarDate(row[c.key] as string | null) || '—'
                                }}</span>
                                <span
                                    v-else-if="c.format === 'datetime'"
                                    class="whitespace-nowrap"
                                    >{{
                                        formatDateTime(
                                            row[c.key] as string | null,
                                            provenance.timezone,
                                        ) || '—'
                                    }}</span
                                >
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
                aria-live="polite"
            />
        </template>
    </main>
</template>
